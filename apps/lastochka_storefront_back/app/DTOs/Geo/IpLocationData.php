<?php

declare(strict_types=1);

namespace App\DTOs\Geo;

/**
 * Геолокация внешнего IP-адреса клиента.
 */
final class IpLocationData
{
    public function __construct(
        /** ISO 3166-1 alpha-2, например «RU». */
        public readonly string $countryCode,
        /** Название города на русском; null — провайдер город не определил. */
        public readonly ?string $city,
    ) {}
}
