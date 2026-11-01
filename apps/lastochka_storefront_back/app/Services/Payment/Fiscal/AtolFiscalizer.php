<?php

declare(strict_types=1);

namespace App\Services\Payment\Fiscal;

use App\Contracts\Fiscalizer;
use App\DTOs\Payment\FiscalReceiptResult;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Enums\ReceiptProvider;
use App\Models\Order;
use App\Models\PaymentReceipt;

final class AtolFiscalizer implements Fiscalizer
{
    public function __construct(
        private AtolClient $client,
        private AtolReceiptBuilder $builder,
    ) {}

    public function fiscalizeSale(Order $order, PaymentType $paymentType, float $amount): FiscalReceiptResult
    {
        return $this->register($order, $paymentType, $amount, 'sell', 'payment', 'sell');
    }

    /**
     * Возврат. `$refundId` делает external_id уникальным на КОНКРЕТНЫЙ возврат:
     * без него два разных частичных возврата одного заказа схлопнутся в один
     * external_id ("{order}-sell_refund") и АТОЛ задедупит второй чек. Если
     * `$refundId` не передан (обратная совместимость) — сохраняем старый
     * operation-based external_id, что по-прежнему делает идемпотентным
     * повтор webhook'а по одному и тому же возврату.
     */
    public function fiscalizeRefund(Order $order, float $amount, ?string $refundId = null): FiscalReceiptResult
    {
        $type = $order->payment_type ?? PaymentType::Card;
        $externalIdKey = $refundId !== null ? "refund-{$refundId}" : 'sell_refund';

        return $this->register($order, $type, $amount, 'sell_refund', 'refund', $externalIdKey);
    }

    /**
     * @param  string  $operation  Эндпоинт ATOL API ('sell'|'sell_refund') — НЕ меняется от refundId.
     * @param  string  $externalIdKey  Суффикс external_id: 'sell' для продаж, 'sell_refund' или
     *                                 'refund-{id}' для возвратов — единственное, что должно быть
     *                                 уникальным на конкретную операцию/возврат.
     */
    private function register(Order $order, PaymentType $paymentType, float $amount, string $operation, string $receiptType, string $externalIdKey): FiscalReceiptResult
    {
        $externalId = "{$order->id}-{$externalIdKey}";
        $receipt = $this->builder->build($order, $paymentType, $amount);
        $response = $this->client->register($operation, $receipt, $externalId);

        $uuid = $response['uuid'] ?? null;
        $failed = ($response['status'] ?? null) === 'fail' || $uuid === null;

        PaymentReceipt::updateOrCreate(
            ['provider' => ReceiptProvider::Atol->value, 'external_id' => $uuid ?? $externalId],
            [
                'order_id' => $order->id,
                'type' => $receiptType,
                'status' => $failed ? PaymentTransactionStatus::Canceled->value : PaymentTransactionStatus::Pending->value,
                'amount' => round($amount, 2),
                'items' => $this->unifyItems($receipt['items']),
                'raw' => $response,
            ],
        );

        if ($failed) {
            throw new \RuntimeException('ATOL register failed: '.json_encode($response['error'] ?? $response, JSON_UNESCAPED_UNICODE));
        }

        return new FiscalReceiptResult(ReceiptProvider::Atol, $uuid, 'pending', raw: $response);
    }

    /**
     * ATOL receipt items (`{name, price, quantity, sum, payment_method, payment_object,
     * vat: {type}}`) → unified stored shape used by both providers in
     * `payment_receipts.items` (matches YooKassa `ReceiptSyncService::mapItems()` /
     * what `OrderReceiptResource::withVat()` reads): `{description, quantity, amount,
     * sum, vat_code, unit}`. `amount` — цена ЗА ЕДИНИЦУ (не сумма строки), `sum` —
     * стоимость строки из самого фискального документа. Формат запроса к ATOL API
     * (`AtolClient::register`) НЕ меняется — маппинг только для того, что сохраняется в БД.
     *
     * `sum` хранится отдельно, а не восстанавливается из `amount × quantity`, потому что
     * первичен именно он: `AtolReceiptBuilder::distribute()` считает цену как
     * `round(sum / quantity, 2)`, и ККТ принимает расхождение произведения с суммой строки
     * до копейки. Для весовой позиции обратное умножение поэтому может дать не ту сумму,
     * что напечатана в чеке (99.00 ₽/кг × 2.024 кг = 200.376 против sum 200.38), а клиент
     * обязан показывать ровно цифры фискального документа.
     *
     * Весовые позиции уходят в чек в кг/л (см. AtolReceiptBuilder::quantityAndUnit), поэтому
     * `quantity` хранится с точностью до грамма («1.078»; для целых и сотых — как раньше,
     * «1.00»/«0.63»), а `unit` — человекочитаемая единица («кг»/«л»; null — штуки), чтобы
     * клиент мог показать «× 0.63 кг», а не голое «× 0.63».
     *
     * @param  list<array<string, mixed>>  $atolItems
     * @return list<array<string, mixed>>
     */
    private function unifyItems(array $atolItems): array
    {
        return array_map(static fn (array $i): array => [
            'description' => (string) ($i['name'] ?? ''),
            'quantity' => self::formatQuantity((float) ($i['quantity'] ?? 1)),
            'amount' => number_format((float) ($i['price'] ?? 0), 2, '.', ''),
            'sum' => number_format(
                (float) ($i['sum'] ?? (float) ($i['price'] ?? 0) * (float) ($i['quantity'] ?? 1)),
                2,
                '.',
                '',
            ),
            'unit' => self::unitLabel($i),
            'vat_code' => match ($i['vat']['type'] ?? 'none') {
                'none' => 1,
                'vat0' => 2,
                'vat10' => 3,
                'vat20' => 4,
                'vat110' => 5,
                'vat120' => 6,
                'vat22' => 11,
                'vat122' => 12,
                default => 1,
            },
        ], $atolItems);
    }

    /**
     * «1.000» → «1.00», «0.630» → «0.63», «1.078» → «1.078»: минимум два знака (совместимо
     * с прежним форматом и ЮKassa), третий — только если он значащий.
     */
    private static function formatQuantity(float $quantity): string
    {
        $formatted = number_format($quantity, 3, '.', '');

        return str_ends_with($formatted, '0') ? mb_substr($formatted, 0, -1) : $formatted;
    }

    /**
     * Единица измерения строки чека в человекочитаемом виде из формата запроса АТОЛ:
     * ФФД 1.05 — строка `measurement_unit` («кг»/«л»), ФФД 1.2 — код `measure`
     * (11 = килограмм, 41 = литр). Штуки (нет тега / measure 0) → null.
     *
     * @param  array<string, mixed>  $atolItem
     */
    private static function unitLabel(array $atolItem): ?string
    {
        if (isset($atolItem['measurement_unit'])) {
            return (string) $atolItem['measurement_unit'];
        }

        return match ($atolItem['measure'] ?? 0) {
            11 => 'кг',
            41 => 'л',
            default => null,
        };
    }
}
