<?php

namespace App\GraphQL\Queries\Category1cQueries;

use App\Models\Category;
use App\Models\Category1c;

/**
 * Есть ли среди привязанных к этой витринной категории 1С-категорий хотя бы одна,
 * удалённая в источнике (soft-deleted в `categories_1c`).
 *
 * Используется UI-бейджем "источники удалены в 1С".
 */
class HasOrphanSources
{
    public function __invoke(Category $root, array $args): bool
    {
        $shopId = (int) $args['shop_id'];

        return Category1c::onlyTrashed()
            ->where('shop_id', $shopId)
            ->whereHas('showcaseCategories', fn($q) => $q->where('categories.id', $root->id))
            ->exists();
    }
}
