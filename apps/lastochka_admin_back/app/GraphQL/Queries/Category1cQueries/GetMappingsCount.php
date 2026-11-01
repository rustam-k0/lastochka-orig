<?php

namespace App\GraphQL\Queries\Category1cQueries;

use App\Models\Category;
use App\Models\Category1c;

class GetMappingsCount
{
    public function __invoke(Category $root, array $args): int
    {
        $shopId = (int) $args['shop_id'];

        return Category1c::query()
            ->where('shop_id', $shopId)
            ->whereHas('showcaseCategories', fn($q) => $q->where('categories.id', $root->id))
            ->count();
    }
}
