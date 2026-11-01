<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\PaymentTransactionStatus;
use App\Enums\ReceiptProvider;
use App\Services\IntegrationConfigService;
use App\Services\Payment\ReceiptQrBuilder;
use App\Support\Vat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PaymentReceipt
 */
final class OrderReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isFiscalized = $this->status === PaymentTransactionStatus::Succeeded
            && $this->fiscal_storage_number !== null;

        $items = $this->withVat($this->items ?? []);

        return [
            'type' => $this->type->value,
            'status' => $this->status->value,
            /** @var string|null @format date-time */
            'registered_at' => $this->registered_at?->toIso8601String(),
            'total' => number_format((float) $this->amount, 2, '.', ''),
            /**
             * Позиции чека: `{description, quantity, amount, sum, vat_code, vat_rate, vat_amount, unit}`.
             * `amount` — цена за единицу, `quantity` — количество, `sum` — стоимость строки
             * («2 × 81 ₽ = 162 ₽»); у весовых позиций АТОЛ количество в кг/л с точностью до
             * грамма («0.63», «1.078») и `unit` = «кг»/«л». Для штучных `unit` = null;
             * в чеках, пробитых до появления поля, ключа нет.
             * @var list<array<string, mixed>>
             */
            'items' => $items,
            'vat' => $this->vatSummary($items),
            /** Итоговая сумма НДС по чеку (сумма по всем ставкам). */
            'vatTotal' => $this->vatTotal($items),
            'fiscal' => $isFiscalized ? [
                'fn' => $this->fiscal_storage_number,
                'fd' => $this->fiscal_document_number,
                'fp' => $this->fiscal_attribute,
            ] : null,
            /** @var string|null */
            'receiptUrl' => $this->receipt_url,
            /** @var string|null */
            'qr' => app(ReceiptQrBuilder::class)->build($this->resource),
            /** @var array{name: string|null, inn: string|null} */
            'seller' => $this->sellerInfo(),
        ];
    }

    /**
     * Продавец в чеке. Если активна фискализация через АТОЛ и в её настройках
     * заполнены реквизиты продавца — берём их оттуда; иначе — fallback на
     * общий конфиг ЮKassa (`services.yookassa.seller`).
     *
     * @return array{name: ?string, inn: ?string}
     */
    private function sellerInfo(): array
    {
        $config = IntegrationConfigService::get('fiscal');

        // RabbitMQ propagates credentials FLAT (IntegrationConfigEventTransformer::toEvent
        // sends only the active driver's sub-dict, no `atol` wrapper key). Accept both shapes.
        $atolCredentials = $config?->driver === ReceiptProvider::Atol->value
            ? ($config->credentials['atol'] ?? $config->credentials ?? [])
            : [];

        return [
            'name' => $atolCredentials['seller_name'] ?? config('services.yookassa.seller.name'),
            'inn' => $atolCredentials['inn'] ?? config('services.yookassa.seller.inn'),
        ];
    }

    /**
     * Дополнить позиции чека стоимостью строки, ставкой и суммой НДС.
     *
     * `sum` отдаём всегда, даже для чеков, пробитых до появления поля в хранимой
     * форме: клиенту нужна готовая стоимость строки («2 × 81 ₽ = 162 ₽»), а не
     * право перемножить самому. Считать произведение на клиенте нельзя — цена
     * позиции АТОЛ восстановлена как `round(sum / quantity, 2)` и у весовых строк
     * обратное умножение расходится с фискальным документом на копейку.
     *
     * ЮKassa в чеке отдаёт только код ставки (`vat_code`), без денежной суммы
     * налога. НДС в рознице включён в цену («в том числе»), поэтому сумма
     * считается из стоимости позиции: для 20% — sum × 20/120, для 10% —
     * sum × 10/110; для «без НДС»/«0%» — 0.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function withVat(array $items): array
    {
        return array_map(static function (array $item): array {
            $vatCode = (int) ($item['vat_code'] ?? 1);
            $rate = Vat::rateFromCode($vatCode);
            $lineTotal = isset($item['sum'])
                ? (float) $item['sum']
                : (float) ($item['amount'] ?? 0) * (float) ($item['quantity'] ?? 1);

            return [
                ...$item,
                'sum' => number_format($lineTotal, 2, '.', ''),
                'vat_code' => $vatCode,
                'vat_rate' => (string) $rate,
                'vat_amount' => number_format(Vat::includedVat($lineTotal, $rate), 2, '.', ''),
            ];
        }, $items);
    }

    /**
     * Итог по налогу: сумма НДС всех позиций чека.
     *
     * Единой «ставки заказа» у чека не существует (у каждой позиции своя,
     * см. теги 1102-1107), поэтому итогом может быть только сумма налога —
     * её и отдаём готовой, чтобы клиенты не складывали `vat[]` каждый сам.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function vatTotal(array $items): string
    {
        $total = array_reduce(
            $items,
            static fn (float $carry, array $item): float => $carry + (float) $item['vat_amount'],
            0.0,
        );

        return number_format($total, 2, '.', '');
    }

    /**
     * Свод НДС по чеку, сгруппированный по ставке (блок «СУММА НДС» фискального чека).
     * Сумма по группе = сумма посчитанных НДС позиций этой ставки — согласована
     * с тем, что показано в каждой позиции.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function vatSummary(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $vatCode = (int) $item['vat_code'];
            $groups[$vatCode] ??= [
                'vat_code' => $vatCode,
                'rate' => $item['vat_rate'],
                'amount' => 0.0,
            ];
            $groups[$vatCode]['amount'] += (float) $item['vat_amount'];
        }

        ksort($groups);

        return array_values(array_map(static fn (array $g): array => [
            'vat_code' => $g['vat_code'],
            'rate' => $g['rate'],
            'amount' => number_format($g['amount'], 2, '.', ''),
        ], $groups));
    }

}
