<?php

namespace App\Services\Pricing;

/**
 * Результат расчёта цены позиции по снапшоту лесенки (MID-308).
 */
final class PriceTierQuote
{
    public float $unitPrice;

    public ?float $unitPriceOld;

    public bool $isEligible;

    public bool $tierApplied;

    public function __construct(float $unitPrice, ?float $unitPriceOld, bool $isEligible, bool $tierApplied)
    {
        $this->unitPrice = $unitPrice;
        $this->unitPriceOld = $unitPriceOld;
        $this->isEligible = $isEligible;
        $this->tierApplied = $tierApplied;
    }
}
