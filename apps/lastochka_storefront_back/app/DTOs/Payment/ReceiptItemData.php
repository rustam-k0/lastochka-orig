<?php

declare(strict_types=1);

namespace App\DTOs\Payment;

/**
 * Позиция чека YooKassa (54-ФЗ).
 *
 * Каждая позиция передаётся с quantity = 1 и amount = полная стоимость позиции
 * (со скидкой). Это не зависит от единицы измерения товара (штуки/граммы/кг)
 * и гарантирует, что сумма позиций чека совпадёт с суммой платежа до копейки.
 */
final readonly class ReceiptItemData
{
    public function __construct(
        public string $description,
        public float $amount,
        public int $vatCode,
        public float $quantity = 1.0,
        public string $paymentSubject = 'commodity',
        public string $paymentMode = 'full_prepayment',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            // description в чеке ограничен 128 символами. mb_substr — строки
            // могут содержать кириллицу, обрезка по байтам сломала бы UTF-8.
            'description' => mb_substr($this->description, 0, 128),
            'quantity' => number_format($this->quantity, 2, '.', ''),
            'amount' => [
                'value' => number_format($this->amount, 2, '.', ''),
                'currency' => 'RUB',
            ],
            'vat_code' => $this->vatCode,
            'payment_subject' => $this->paymentSubject,
            'payment_mode' => $this->paymentMode,
        ];
    }
}
