<?php

declare(strict_types=1);

namespace App\Services\Comparison;

use App\Models\Comparison;
use App\Models\ComparisonItem;
use App\Models\User;

/**
 * Состав сравнения текущего пользователя для витрины товаров.
 *
 * ProductResource спрашивает признак на каждый товар выдачи, поэтому список
 * товаров в сравнении загружается один раз за запрос и дальше отвечает из
 * памяти: число обращений к comparison_items не зависит от размера выдачи.
 *
 * Регистрируется как scoped в AppServiceProvider — кеш живёт ровно один
 * HTTP-запрос.
 */
final class UserComparisonResolver
{
    /** @var array<int, true>|null product_id => true; null пока не загружено */
    private ?array $productIds = null;

    private ?int $loadedForUserId = null;

    /**
     * Лежит ли товар в сравнении пользователя.
     * Для гостя — всегда false.
     */
    public function contains(?User $user, int $productId): bool
    {
        if ($user === null) {
            return false;
        }

        $userId = (int) $user->id;

        if ($this->productIds === null || $this->loadedForUserId !== $userId) {
            $this->productIds = $this->loadFor($userId);
            $this->loadedForUserId = $userId;
        }

        return isset($this->productIds[$productId]);
    }

    /**
     * @return array<int, true>
     */
    private function loadFor(int $userId): array
    {
        $ids = ComparisonItem::query()
            ->whereIn(
                'comparison_id',
                Comparison::query()->select('id')->where('user_id', $userId)
            )
            ->pluck('product_id')
            ->all();

        return array_fill_keys(array_map('intval', $ids), true);
    }
}
