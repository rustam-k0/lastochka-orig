<?php

namespace App\DTOs\Delivery;

readonly class YandexDeliveryMethodsData
{
    public function __construct(
        public bool $expressAllowed,
        public array $availableTariffs = [],
    ) {}

    public static function fromApiResponse(array $data): self
    {
        $express = $data['express_delivery'] ?? [];

        return new self(
            expressAllowed: (bool) ($express['allowed'] ?? false),
            availableTariffs: $express['available_tariffs'] ?? [],
        );
    }

    public static function unavailable(): self
    {
        return new self(expressAllowed: false);
    }
}
