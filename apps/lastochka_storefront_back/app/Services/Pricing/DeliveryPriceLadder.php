<?php

namespace App\Services\Pricing;

/**
 * Лесенка стоимости доставки от суммы заказа (MID-280): цена доставки зоны и
 * ступени «от суммы товаров from_amount: доставка price». Суммы в целых рублях.
 * Ступень действует от своей from_amount включительно до from_amount следующей,
 * ниже первой ступени действует базовая цена. Чистая функция без обращений к БД;
 * копии побайтно одинаковы в admin_back, storefront_back и delivery_back.
 */
final class DeliveryPriceLadder
{
    public const MAX_RANGES = 20;

    public const MAX_VALUE = 2147483647;

    private int $basePrice;

    /** @var array<int, array{from_amount: int, price: int}> */
    private array $ranges;

    /**
     * @param  array<int, array{from_amount: int, price: int}>  $ranges
     */
    private function __construct(int $basePrice, array $ranges)
    {
        $this->basePrice = max(0, $basePrice);
        $this->ranges = $ranges;
    }

    /**
     * @param  mixed  $ranges  ступени [{from_amount, price}] (массив либо JSON-строка); битые → лесенка без ступеней
     */
    public static function fromRanges(int $basePrice, $ranges): self
    {
        return new self($basePrice, self::normalize($ranges) ?? []);
    }

    /**
     * Лесенка из пары «цена зоны + порог бесплатной доставки» (где ступени неизвестны).
     */
    public static function fromFreeDeliveryFrom(int $basePrice, ?int $freeDeliveryFrom): self
    {
        $ranges = $freeDeliveryFrom !== null && $freeDeliveryFrom >= 0
            ? [['from_amount' => $freeDeliveryFrom, 'price' => 0]]
            : [];

        return new self($basePrice, $ranges);
    }

    /**
     * Структурная нормализация ступеней: по возрастанию from_amount, целые 0..MAX_VALUE,
     * без повторов from_amount, не больше MAX_RANGES. null/пустая строка → [].
     * Битое значение → null (бизнес-правила вроде «цена не растёт» проверяет валидатор админки).
     *
     * @param  mixed  $ranges
     * @return array<int, array{from_amount: int, price: int}>|null
     */
    public static function normalize($ranges): ?array
    {
        if ($ranges === null || $ranges === '') {
            return [];
        }

        if (is_string($ranges)) {
            $ranges = json_decode($ranges, true);
        }

        if (! is_array($ranges) || count($ranges) > self::MAX_RANGES) {
            return null;
        }

        $normalized = [];

        foreach ($ranges as $range) {
            if (! is_array($range) || ! array_key_exists('from_amount', $range) || ! array_key_exists('price', $range)) {
                return null;
            }

            $fromAmount = self::toAmount($range['from_amount']);
            $price = self::toAmount($range['price']);

            if ($fromAmount === null || $price === null || array_key_exists($fromAmount, $normalized)) {
                return null;
            }

            $normalized[$fromAmount] = ['from_amount' => $fromAmount, 'price' => $price];
        }

        ksort($normalized);

        return array_values($normalized);
    }

    /**
     * Цена доставки для суммы товаров (до промокода и бонусов).
     */
    public function priceFor(float $amount): int
    {
        $amount = round($amount, 2);
        $price = $this->basePrice;

        foreach ($this->ranges as $range) {
            if ($range['from_amount'] > $amount) {
                break;
            }

            $price = $range['price'];
        }

        return $price;
    }

    /**
     * Ближайшая по сумме ступень дешевле текущей цены: сколько рублей до неё осталось.
     * Ступени с той же ценой пропускаются; null, если дешевле уже некуда.
     *
     * @return array{from_amount: int, price: int, amount_left: float}|null
     */
    public function nextStep(float $amount): ?array
    {
        $amount = round($amount, 2);
        $current = $this->priceFor($amount);

        foreach ($this->ranges as $range) {
            if ($range['from_amount'] > $amount && $range['price'] < $current) {
                return [
                    'from_amount' => $range['from_amount'],
                    'price' => $range['price'],
                    'amount_left' => round($range['from_amount'] - $amount, 2),
                ];
            }
        }

        return null;
    }

    /**
     * Сумма, с которой доставка бесплатна (ступень с ценой 0), либо null.
     */
    public function freeFrom(): ?int
    {
        foreach ($this->ranges as $range) {
            if ($range['price'] === 0) {
                return $range['from_amount'];
            }
        }

        return null;
    }

    public function basePrice(): int
    {
        return $this->basePrice;
    }

    /**
     * @return array<int, array{from_amount: int, price: int}>
     */
    public function ranges(): array
    {
        return $this->ranges;
    }

    /**
     * @return array{base_price: int, ranges: array<int, array{from_amount: int, price: int}>}
     */
    public function toArray(): array
    {
        return [
            'base_price' => $this->basePrice,
            'ranges' => $this->ranges,
        ];
    }

    /**
     * @param  mixed  $value
     */
    private static function toAmount($value): ?int
    {
        if (is_bool($value) || ! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        if ($number < 0 || $number > self::MAX_VALUE || floor($number) !== $number) {
            return null;
        }

        return (int) $number;
    }
}
