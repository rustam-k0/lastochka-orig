<?php

namespace App\GraphQL\Queries\Category1cQueries;

use App\Models\Category1cSyncBatch;

/**
 * Возвращает последний (по id) batch синхронизации 1С-категорий для магазина.
 * Может вернуть PENDING/IN_PROGRESS (текущий) или COMPLETED/FAILED (история), либо null.
 */
class GetCurrentBatch
{
    public function __invoke($_, array $args): ?Category1cSyncBatch
    {
        $shopId = (int) $args['shop_id'];

        return Category1cSyncBatch::query()
            ->where('shop_id', $shopId)
            ->latest('id')
            ->first();
    }
}
