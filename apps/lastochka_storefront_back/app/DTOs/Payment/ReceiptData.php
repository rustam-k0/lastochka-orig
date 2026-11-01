<?php

declare(strict_types=1);

namespace App\DTOs\Payment;

/**
 * Чек для онлайн-кассы YooKassa (54-ФЗ).
 *
 * Передаётся в объекте receipt запроса POST /payments (и /payments/{id}/capture,
 * /refunds). Обязателен customer (хотя бы phone или email) и непустой items.
 *
 * @see https://yookassa.ru/developers/payment-acceptance/receipts/54fz/parameters-values
 */
final readonly class ReceiptData
{
    /**
     * @param  list<ReceiptItemData>  $items
     */
    public function __construct(
        public array $items,
        public ?string $customerPhone = null,
        public ?string $customerEmail = null,
        public ?int $taxSystemCode = null,
    ) {}

    public function hasItems(): bool
    {
        return $this->items !== [];
    }

    /**
     * Суммарная стоимость всех позиций (для сверки с суммой платежа).
     */
    public function total(): float
    {
        return array_reduce(
            $this->items,
            static fn (float $carry, ReceiptItemData $item): float => $carry + $item->amount * $item->quantity,
            0.0,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $customer = [];
        if ($this->customerPhone !== null && $this->customerPhone !== '') {
            $customer['phone'] = $this->customerPhone;
        }
        if ($this->customerEmail !== null && $this->customerEmail !== '') {
            $customer['email'] = $this->customerEmail;
        }

        $receipt = [
            'customer' => $customer,
            'items' => array_map(
                static fn (ReceiptItemData $item): array => $item->toArray(),
                $this->items,
            ),
        ];

        if ($this->taxSystemCode !== null) {
            $receipt['tax_system_code'] = $this->taxSystemCode;
        }

        return $receipt;
    }
}
