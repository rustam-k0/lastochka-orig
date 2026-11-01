<?php

namespace App\GraphQL\Queries\Category1cQueries;

use App\Models\Category;
use App\Models\Category1c;
use Illuminate\Database\Eloquent\Collection;

/**
 * Возвращает 1С-категории, привязанные к данной витринной Category в указанном магазине.
 * Используется на UI карточки витринной категории как "источники из 1С".
 */
class GetMappings
{
    public function __invoke(Category $root, array $args): Collection
    {
        $shopId = (int) $args['shop_id'];

        return Category1c::query()
            ->where('shop_id', $shopId)
            ->whereHas('showcaseCategories', fn($q) => $q->where('categories.id', $root->id))
            ->orderBy('name')
            ->get();
    }
}
