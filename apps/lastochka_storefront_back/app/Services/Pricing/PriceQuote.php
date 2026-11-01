<?php

declare(strict_types=1);

namespace App\Services\Pricing;

/**
 * Котировка цены товара для количества (MID-308).
 */
final class PriceQuote
{
    public function __construct(
        public readonly float $price,
        public readonly ?float $priceOld,
        public readonly bool $tierApplied,
    ) {}
}
