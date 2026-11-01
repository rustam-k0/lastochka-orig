<?php

namespace App\RabbitMQ\DTO;

class BonusOperationDTO
{
    public function __construct(
        public readonly string $operationId,
        public readonly ?string $loyaltyId,
        public readonly string $type,
        public readonly int $amount,
        public readonly string $source,
        public readonly ?string $orderExternalId,
    ) {}

    public static function fromMessage(array $m): self
    {
        $p = $m['payload'] ?? $m;
        return new self(
            operationId: (string) ($p['operation_id'] ?? ''),
            loyaltyId: $p['loyalty_id'] ?? null,
            type: (string) ($p['type'] ?? ''),
            amount: (int) ($p['amount'] ?? 0),
            source: (string) ($p['operation_source'] ?? $p['source'] ?? 'online'),
            orderExternalId: $p['order_external_id'] ?? null,
        );
    }
}
