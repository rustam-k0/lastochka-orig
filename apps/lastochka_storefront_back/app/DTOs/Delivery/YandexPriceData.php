<?php

namespace App\DTOs\Delivery;

readonly class YandexPriceData
{
    public function __construct(
        public float $price,
        public float $eta,
        public string $currency,
        public float $distanceMeters,
        public string $zoneId,
    ) {}

    public static function fromApiResponse(array $data): self
    {
        return new self(
            price: (float) $data['price'],
            eta: (float) $data['eta'],
            currency: $data['currency_rules']['code'] ?? 'RUB',
            distanceMeters: (float) ($data['distance_meters'] ?? 0),
            zoneId: $data['zone_id'] ?? '',
        );
    }

    public function priceRounded(): int
    {
        return (int) ceil($this->price);
    }
}
