<?php

namespace App\Services\Pricing;

/**
 * Снапшот лесенки «цены от количества» в позиции заказа (MID-308).
 * Всё в порциях и ценах за порцию. Чистая функция quote() даёт одинаковый
 * результат во всех сервисах и не обращается к живым ценам товара.
 */
final class PriceTierSnapshot
{
    private float $basePrice;

    private ?float $basePriceOld;

    private bool $baseIsEligible;

    /** @var array<int, array{min_quantity: float, price: float}> */
    private array $tiers;

    /**
     * @param  array<int, array{min_quantity: float, price: float}>  $tiers
     */
    private function __construct(float $basePrice, ?float $basePriceOld, bool $baseIsEligible, array $tiers)
    {
        $this->basePrice = $basePrice;
        $this->basePriceOld = $basePriceOld;
        $this->baseIsEligible = $baseIsEligible;
        $this->tiers = $tiers;
    }

    /**
     * @param  mixed  $tiers  лесенка в порциях; пустая или невалидная → снапшота нет
     */
    public static function build(float $basePrice, ?float $basePriceOld, bool $baseIsEligible, $tiers): ?self
    {
        $ladder = PriceTierLadder::normalize($tiers);

        if ($ladder === null || $ladder === []) {
            return null;
        }

        return new self(
            round($basePrice, 2),
            $basePriceOld !== null ? round($basePriceOld, 2) : null,
            $baseIsEligible,
            $ladder
        );
    }

    /**
     * @param  mixed  $data  значение колонки order_items.price_tiers или ключа события (массив либо JSON-строка)
     */
    public static function fromArray($data): ?self
    {
        if (is_string($data)) {
            $data = json_decode($data, true);
        }

        if (! is_array($data) || ! array_key_exists('tiers', $data)) {
            return null;
        }

        return self::build(
            (float) ($data['base_price'] ?? 0),
            isset($data['base_price_old']) ? (float) $data['base_price_old'] : null,
            (bool) ($data['base_is_eligible'] ?? true),
            $data['tiers']
        );
    }

    /**
     * @return array{base_price: float, base_price_old: float|null, base_is_eligible: bool, tiers: array<int, array{min_quantity: float, price: float}>}
     */
    public function toArray(): array
    {
        return [
            'base_price' => $this->basePrice,
            'base_price_old' => $this->basePriceOld,
            'base_is_eligible' => $this->baseIsEligible,
            'tiers' => $this->tiers,
        ];
    }

    public function basePrice(): float
    {
        return $this->basePrice;
    }

    public function quote(float $portions): PriceTierQuote
    {
        $applied = null;

        foreach ($this->tiers as $tier) {
            if ($tier['min_quantity'] > $portions + 0.0005) {
                break;
            }

            $applied = $tier;
        }

        if ($applied === null) {
            return new PriceTierQuote($this->basePrice, $this->basePriceOld, $this->baseIsEligible, false);
        }

        $first = $this->tiers[0];
        $reference = $first['min_quantity'] <= 1.0005
            ? $first['price']
            : ($this->basePriceOld ?? $this->basePrice);

        $unitPriceOld = $applied['price'] < $reference ? $reference : null;

        return new PriceTierQuote($applied['price'], $unitPriceOld, $unitPriceOld === null, true);
    }
}
