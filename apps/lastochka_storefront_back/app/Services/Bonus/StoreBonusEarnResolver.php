<?php

declare(strict_types=1);

namespace App\Services\Bonus;

use App\Models\Product;
use App\Models\Store;

/**
 * Процент начисления бонусов магазина для витрины товаров.
 *
 * ProductResource рендерится пачками по 100 позиций, поэтому процент
 * резолвится через кеш в рамках запроса: сколько бы товаров ни было
 * в выдаче, запросов к `stores` будет столько же, сколько уникальных
 * магазинов (обычно один). Связь `store` на модели, если она уже
 * загружена, используется без похода в БД.
 *
 * Регистрируется как scoped в AppServiceProvider — кеш живёт ровно
 * один HTTP-запрос.
 */
final class StoreBonusEarnResolver
{
    /** @var array<int, int> storeId => процент начисления (0 если бонусы выключены) */
    private array $percents = [];

    /**
     * Процент начисления бонусов магазина, которому принадлежит товар.
     * Возвращает 0, если бонусы у магазина выключены или магазина нет.
     */
    public function earnPercentForProduct(Product $product): int
    {
        $storeId = $product->store_id;

        if ($storeId === null) {
            return 0;
        }

        $storeId = (int) $storeId;

        if ($product->relationLoaded('store')) {
            return $this->percents[$storeId] ??= $this->percentOf($product->store);
        }

        return $this->percents[$storeId] ??= $this->percentOf(
            Store::query()
                ->select(['id', 'is_bonus_enabled', 'bonus_earn_percent'])
                ->find($storeId)
        );
    }

    private function percentOf(?Store $store): int
    {
        if ($store === null || ! $store->is_bonus_enabled) {
            return 0;
        }

        return max(0, (int) ($store->bonus_earn_percent ?? 0));
    }
}
