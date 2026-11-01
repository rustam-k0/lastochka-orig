<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Store;
use App\Services\Settings\ModuleConfigService;

/**
 * Признак «Тяжёлый заказ»: вес позиций одного магазина против порога
 * stores.heavy_order_threshold (граммы). Единая точка логики для
 * чекаута (CreateOrderAction) и ресурсов корзины.
 */
final class HeavyOrderResolver
{
    public function __construct(private readonly ModuleConfigService $modules) {}

    public function isHeavy(?Store $store, ?int $totalGrams): bool
    {
        // Признак активен при heavy_orders ИЛИ courier_assignment: совместная
        // доставка тяжёлых входит в «Назначение курьера сборщиком» (Platrum 12180)
        if (! $this->modules->isHeavyOrdersEnabled() && ! $this->modules->isCourierAssignmentEnabled()) {
            return false;
        }

        $threshold = $store?->heavy_order_threshold;

        return $threshold !== null
            && $threshold > 0
            && $totalGrams !== null
            && $totalGrams > $threshold;
    }

    /**
     * @param  iterable<object>  $items  позиции ОДНОГО магазина (корневые, без добавок)
     */
    public function isHeavyForItems(?Store $store, iterable $items): bool
    {
        return $this->isHeavy($store, WeightAggregator::sumGrams($items));
    }
}
