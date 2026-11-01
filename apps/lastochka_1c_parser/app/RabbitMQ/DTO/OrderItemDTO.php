<?php

namespace App\RabbitMQ\DTO;

class OrderItemDTO
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $name,
        public readonly float $quantity,
        public readonly float $price,
        public readonly float $total,
    ) {}

    public static function fromArray(array $data): self
    {
        // Реальный контракт события (пакет comrades/laravel-rabbitmq-events): курьерка
        // присылает product_id / product_name / unit_price[_effective] / line_total / quantity.
        // Старые ключи (name / price / total) поддерживаем как fallback.
        $quantity = (float) ($data['quantity'] ?? 1);
        $price = (float) ($data['unit_price_effective'] ?? $data['unit_price'] ?? $data['price'] ?? 0);
        $total = isset($data['line_total'])
            ? (float) $data['line_total']
            : (float) ($data['total'] ?? ($quantity * $price));

        return new self(
            externalId: (string) ($data['product_id'] ?? $data['external_id'] ?? ''),
            name: (string) ($data['product_name'] ?? $data['name'] ?? ''),
            quantity: $quantity,
            price: $price,
            total: $total,
        );
    }
}
