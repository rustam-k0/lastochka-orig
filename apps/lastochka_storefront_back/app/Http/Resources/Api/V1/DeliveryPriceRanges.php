<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Services\Settings\ModuleConfigService;

/**
 * Ступени стоимости доставки в wire-контракте витрины (camelCase).
 *
 * Гейт модуля delivery_price_ranges (MID-280): выключен — отдаём пустой
 * контракт (`[]` / `null`), даже если лесенка внутри уже содержит ступень
 * бесплатной доставки (см. ZoneDeliveryLadder). Флаг гейтит только выдачу,
 * снимок заказа эти методы не читает.
 */
final class DeliveryPriceRanges
{
    /**
     * @param  array<int, array{from_amount: int, price: int}>  $ranges
     * @return array<int, array{fromAmount: int, price: int}>
     */
    public static function ranges(array $ranges): array
    {
        if (! app(ModuleConfigService::class)->isDeliveryPriceRangesEnabled()) {
            return [];
        }

        return array_map(fn (array $range): array => [
            'fromAmount' => (int) $range['from_amount'],
            'price' => (int) $range['price'],
        ], array_values($ranges));
    }

    /**
     * @param  array{from_amount: int, price: int, amount_left: float}|null  $step
     * @return array{fromAmount: int, price: int, amountLeft: float}|null
     */
    public static function nextStep(?array $step): ?array
    {
        if ($step === null) {
            return null;
        }

        if (! app(ModuleConfigService::class)->isDeliveryPriceRangesEnabled()) {
            return null;
        }

        return [
            'fromAmount' => (int) $step['from_amount'],
            'price' => (int) $step['price'],
            'amountLeft' => (float) $step['amount_left'],
        ];
    }
}
