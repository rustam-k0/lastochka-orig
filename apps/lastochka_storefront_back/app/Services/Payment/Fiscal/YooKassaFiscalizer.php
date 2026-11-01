<?php

declare(strict_types=1);

namespace App\Services\Payment\Fiscal;

use App\Contracts\Fiscalizer;
use App\Contracts\PaymentGateway;
use App\DTOs\Payment\FiscalReceiptResult;
use App\Enums\PaymentReceiptType;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Enums\ReceiptProvider;
use App\Models\Order;
use App\Models\PaymentReceipt;
use App\Services\Payment\ReceiptBuilder;

/**
 * Фискализация через ЮKassa.
 *
 * Card: чек формирует сама ЮKassa внутри платежа (POST /payments receipt=...),
 * его статус синхронизирует ReceiptSyncService — тут делать нечего, noop.
 *
 * Cash: деньги идут мимо ЮKassa (наличные сборщику), но 54-ФЗ требует чек —
 * регистрируем независимый чек без движения денег (POST /receipts).
 */
final class YooKassaFiscalizer implements Fiscalizer
{
    public function __construct(
        private PaymentGateway $gateway,
        private ReceiptBuilder $receiptBuilder,
    ) {}

    public function fiscalizeSale(Order $order, PaymentType $paymentType, float $amount): FiscalReceiptResult
    {
        if ($paymentType === PaymentType::Card) {
            // Чек уже уходит внутри платежа ЮKassa — ReceiptSyncService его синхронизирует.
            return new FiscalReceiptResult(ReceiptProvider::Yookassa, null, 'succeeded');
        }

        $receipt = $this->receiptBuilder->forOrder($order, $amount);
        $items = $receipt?->toArray()['items'] ?? [];

        $payload = [
            'type' => PaymentReceiptType::Payment->value,
            'send' => true,
            'customer' => array_filter([
                'phone' => $order->user?->phone,
                'email' => $order->user?->email,
            ]),
            'settlements' => [[
                'type' => 'cash',
                'amount' => [
                    'value' => number_format($amount, 2, '.', ''),
                    'currency' => 'RUB',
                ],
            ]],
            'items' => $items,
        ];

        $response = $this->gateway->createIndependentReceipt($payload);
        $externalId = $response['id'] ?? null;
        $status = $response['status'] ?? PaymentTransactionStatus::Pending->value;

        PaymentReceipt::updateOrCreate(
            ['provider' => ReceiptProvider::Yookassa->value, 'external_id' => $externalId ?? "{$order->id}-independent"],
            [
                'order_id' => $order->id,
                'yookassa_receipt_id' => $externalId,
                'type' => PaymentReceiptType::Payment->value,
                'status' => $status,
                'amount' => round($amount, 2),
                'items' => $this->unifyItems($items),
                'raw' => $response,
            ],
        );

        return new FiscalReceiptResult(ReceiptProvider::Yookassa, $externalId, $status, raw: $response);
    }

    public function fiscalizeRefund(Order $order, float $amount, ?string $refundId = null): FiscalReceiptResult
    {
        // Чек возврата по онлайну уже фискализируется внутри рефанда ЮKassa.
        // $refundId не используется — noop независимо от конкретного возврата.
        return new FiscalReceiptResult(ReceiptProvider::Yookassa, null, 'succeeded');
    }

    /**
     * YooKassa API item shape (`{description, amount: {value, currency}, quantity,
     * vat_code, payment_subject, payment_mode}`, из `ReceiptItemData::toArray()`)
     * → unified stored shape, общий для всех провайдеров в `payment_receipts.items`
     * (совпадает с `ReceiptSyncService::mapItems()` для онлайн-чеков ЮKassa и с
     * `AtolFiscalizer::unifyItems()`), который читает `OrderReceiptResource::withVat()`:
     * `{description, quantity, amount, sum, vat_code}` (amount/quantity/sum — строки).
     *
     * У ЮKassa первична цена за единицу (`amount.value`), стоимости строки в её формате
     * нет — считаем произведением. У АТОЛ наоборот: там `sum` приходит из фискального
     * документа и хранится как есть (см. `AtolFiscalizer::unifyItems()`).
     *
     * ВАЖНО: маппинг применяется только к тому, что сохраняется в БД. `$payload['items']`,
     * отправляемый в `createIndependentReceipt()` (запрос к ЮKassa API), остаётся
     * в исходном (API) формате без изменений.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function unifyItems(array $items): array
    {
        return array_map(static fn (array $i): array => [
            'description' => $i['description'] ?? '',
            'quantity' => (string) ($i['quantity'] ?? '1.00'),
            'amount' => (string) ($i['amount']['value'] ?? '0.00'),
            'sum' => number_format(
                (float) ($i['amount']['value'] ?? 0) * (float) ($i['quantity'] ?? 1),
                2,
                '.',
                '',
            ),
            'vat_code' => $i['vat_code'] ?? null,
        ], $items);
    }
}
