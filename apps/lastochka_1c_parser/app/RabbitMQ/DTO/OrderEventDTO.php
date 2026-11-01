<?php

namespace App\RabbitMQ\DTO;

use Illuminate\Support\Arr;
use InvalidArgumentException;

class OrderEventDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $number,
        public readonly string $status,
        public readonly CustomerDTO $customer,
        public readonly ?string $deliveryAddress,
        public readonly ?string $comment,
        public readonly float $total,
        public readonly array $items,
        public readonly string $source,
        public readonly string $shopCode,
        public readonly string $timestamp,
        public readonly array $rawItems = [],
        public readonly ?string $shippingMethod = null,
        public readonly ?string $paymentType = null,
    ) {}

    public static function fromMessage(array $message): self
    {
        self::validate($message);

        $rawItems = $message['items'] ?? [];

        $items = array_map(
            fn(array $item) => OrderItemDTO::fromArray($item),
            $rawItems
        );

        return new self(
            id: (int) $message['id'],
            number: (string) ($message['externalId'] ?? $message['id']),
            status: $message['status'] ?? 'new',
            customer: CustomerDTO::fromArray($message['client_info'] ?? []),
            deliveryAddress: self::buildDeliveryAddress($message),
            comment: $message['comment'] ?? $message['client_comment'] ?? null,
            total: (float) ($message['grand_total'] ?? $message['total'] ?? 0),
            items: $items,
            source: $message['source'] ?? 'unknown',
            shopCode: $message['shop_code'] ?? 'unknown',
            timestamp: $message['timestamp'] ?? now()->toIso8601String(),
            rawItems: $rawItems,
            shippingMethod: $message['shipping_method'] ?? null,
            paymentType: $message['payment_type'] ?? null,
        );
    }

    /**
     * Собрать строковое представление адреса доставки.
     *
     * Магазин (admin_back/storefront) присылает адрес СТРУКТУРНЫМ объектом под
     * ключом `address` ({address, entrance, intercom, floor, flat, latitude,
     * longitude}), а НЕ плоской строкой `delivery_address`. Раньше DTO читал
     * только `delivery_address` → адрес в 1С не уходил вообще. Собираем
     * человекочитаемую строку для реквизита «АдресРегистрации/Представление».
     */
    public static function buildDeliveryAddress(array $message): ?string
    {
        // Плоская строка имеет приоритет (на случай если придёт готовой).
        $flat = $message['delivery_address'] ?? null;
        if (is_string($flat) && trim($flat) !== '') {
            return trim($flat);
        }

        $address = $message['address'] ?? null;
        if (!is_array($address)) {
            return null;
        }

        $parts = [];
        if (!empty($address['address'])) {
            $parts[] = trim((string) $address['address']);
        }
        if (!empty($address['entrance'])) {
            $parts[] = 'подъезд ' . trim((string) $address['entrance']);
        }
        if (!empty($address['intercom'])) {
            $parts[] = 'домофон ' . trim((string) $address['intercom']);
        }
        if (!empty($address['floor'])) {
            $parts[] = 'этаж ' . trim((string) $address['floor']);
        }
        if (!empty($address['flat'])) {
            $parts[] = 'кв. ' . trim((string) $address['flat']);
        }

        $result = implode(', ', array_filter($parts));

        return $result !== '' ? $result : null;
    }

    public static function validate(array $message): void
    {
        $errors = [];

        if (empty($message['id'])) {
            $errors[] = 'id обязателен';
        }
        
        
        $items = $message['items'] ?? [];
        if (empty($items)) {
            $errors[] = 'items должен содержать минимум 1 позицию';
        }
        
        foreach ($items as $index => $item) {
            if (empty($item['product_id'])) {
                $errors[] = "items[{$index}].product_id обязателен";
            }
        }
        
        $customer = $message['client_info'] ?? [];
        if (empty($customer['phone']) && empty($customer['email'])) {
            $errors[] = 'Требуется хотя бы phone или email покупателя';
        }
        
        if (!empty($errors)) {
            throw new InvalidArgumentException('Ошибки валидации: ' . implode('; ', $errors));
        }
    }
}
