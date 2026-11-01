<?php

declare(strict_types=1);

namespace App\DTOs\Delivery;

readonly class YandexDeliveryInfoData
{
    public function __construct(
        public bool $available,
        public ?int $price = null,
        public ?float $eta = null,
        public ?string $description = null,
        public array $availableSlots = [],
        public int $maxDueHours = 4,
    ) {}

    public static function unavailable(): self
    {
        return new self(available: false);
    }
}
