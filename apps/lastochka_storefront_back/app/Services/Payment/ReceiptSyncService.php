<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentTransactionType;
use App\Enums\ReceiptProvider;
use App\Models\PaymentReceipt;
use App\Models\PaymentTransaction;
use Illuminate\Support\Carbon;

final class ReceiptSyncService
{
    public function __construct(private PaymentGateway $gateway) {}

    /**
     * Синхронизировать чеки одной транзакции из ЮKassa в БД.
     */
    public function syncForTransaction(PaymentTransaction $transaction): void
    {
        if ($transaction->type === PaymentTransactionType::Refund) {
            if (! $transaction->yookassa_refund_id) {
                return;
            }
            $raw = $this->gateway->getReceipts(refundId: $transaction->yookassa_refund_id);
        } else {
            if (! $transaction->yookassa_payment_id) {
                return;
            }
            $raw = $this->gateway->getReceipts(paymentId: $transaction->yookassa_payment_id);
        }

        foreach ($raw as $item) {
            $this->upsert($transaction, $item);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function upsert(PaymentTransaction $transaction, array $item): void
    {
        $receiptId = $item['id'] ?? null;
        if ($receiptId === null) {
            return;
        }

        PaymentReceipt::updateOrCreate(
            ['provider' => ReceiptProvider::Yookassa->value, 'external_id' => $receiptId],
            [
                'order_id' => $transaction->order_id,
                'payment_transaction_id' => $transaction->id,
                'yookassa_receipt_id' => $receiptId,
                'type' => $item['type'] ?? 'payment',
                'status' => $item['status'] ?? 'pending',
                'fiscal_storage_number' => $item['fiscal_storage_number'] ?? null,
                'fiscal_document_number' => $item['fiscal_document_number'] ?? null,
                'fiscal_attribute' => $item['fiscal_attribute'] ?? null,
                'registered_at' => isset($item['registered_at'])
                    ? Carbon::parse($item['registered_at'])
                    : null,
                'amount' => $this->resolveAmount($item),
                'items' => $this->mapItems($item['items'] ?? []),
                'raw' => $item,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveAmount(array $item): float
    {
        $settlements = $item['settlements'] ?? [];
        if ($settlements !== []) {
            return array_reduce(
                $settlements,
                static fn (float $carry, array $s): float => $carry + (float) ($s['amount']['value'] ?? 0),
                0.0,
            );
        }

        return array_reduce(
            $item['items'] ?? [],
            static fn (float $carry, array $i): float =>
                $carry + (float) ($i['amount']['value'] ?? 0) * (float) ($i['quantity'] ?? 1),
            0.0,
        );
    }

    /**
     * Позиция чека ЮKassa → unified-форма `payment_receipts.items`, общая для всех
     * провайдеров (см. `AtolFiscalizer::unifyItems()`): `amount` — цена за единицу,
     * `sum` — стоимость строки. В формате ЮKassa стоимости строки нет, поэтому считаем
     * её произведением; у АТОЛ она приходит из фискального документа.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function mapItems(array $items): array
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
