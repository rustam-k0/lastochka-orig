<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\Pricing\DeliveryPriceLadder;
use Illuminate\Contracts\Validation\InvokableRule;

/**
 * Ступени стоимости доставки зоны при сохранении в админке (аргумент
 * delivery_price_ranges мутации ShopZoneCreteOrUpdate, MID-280).
 *
 * Правило вешается на список целиком, ошибки уходят под ключом
 * delivery_price_ranges. Структуру строк (целые от 0, без повторов суммы,
 * не больше MAX_RANGES) размечают поэлементные правила валидатора (ключи
 * delivery_price_ranges.N.from_amount / .price), здесь только бизнес-правила:
 * цена не растёт с суммой и не выше цены зоны, бесплатная ступень последняя,
 * у Яндекс Доставки только бесплатная ступень.
 */
class DeliveryPriceRangesRule implements InvokableRule
{
    public const MESSAGE_NOT_DECREASING = 'Каждый следующий диапазон должен быть не дороже предыдущего и не дороже цены доставки зоны';
    public const MESSAGE_FREE_NOT_LAST = 'Бесплатная доставка должна быть последним диапазоном';
    public const MESSAGE_YANDEX_PAID = 'Для Яндекс Доставки цену называет Яндекс, можно задать только сумму бесплатной доставки';

    private ?int $basePrice;

    private string $deliveryType;

    /**
     * @param  mixed  $basePrice  аргумент delivery_price мутации
     * @param  mixed  $deliveryType  аргумент delivery_type мутации (не передан = own)
     */
    public function __construct($basePrice, $deliveryType)
    {
        $this->basePrice = is_numeric($basePrice) ? (int) $basePrice : null;
        $this->deliveryType = is_string($deliveryType) && $deliveryType !== '' ? $deliveryType : 'own';
    }

    public function __invoke($attribute, $value, $fail)
    {
        if (!is_array($value) || $value === []) {
            return;
        }

        $ranges = DeliveryPriceLadder::normalize($value);

        // Битые строки размечены поэлементными правилами валидатора
        if ($ranges === null) {
            return;
        }

        if ($this->deliveryType === 'yandex') {
            if (count($ranges) > 1 || $ranges[0]['price'] !== 0) {
                $fail(self::MESSAGE_YANDEX_PAID);
            }

            return;
        }

        if (!$this->isNotIncreasing($ranges)) {
            $fail(self::MESSAGE_NOT_DECREASING);
        }

        if (!$this->isFreeRangeLast($ranges)) {
            $fail(self::MESSAGE_FREE_NOT_LAST);
        }
    }

    /**
     * @param  array<int, array{from_amount: int, price: int}>  $ranges
     */
    private function isNotIncreasing(array $ranges): bool
    {
        $previous = $this->basePrice;

        foreach ($ranges as $range) {
            if ($previous !== null && $range['price'] > $previous) {
                return false;
            }

            $previous = $range['price'];
        }

        return true;
    }

    /**
     * @param  array<int, array{from_amount: int, price: int}>  $ranges
     */
    private function isFreeRangeLast(array $ranges): bool
    {
        $last = count($ranges) - 1;

        foreach ($ranges as $index => $range) {
            if ($range['price'] === 0 && $index !== $last) {
                return false;
            }
        }

        return true;
    }
}
