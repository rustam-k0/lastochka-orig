<?php

namespace App\GraphQL\Queries\Category1cQueries;

use App\Models\Category1c;
use Illuminate\Support\Carbon;

/**
 * Количество новых (созданы < 30 дней назад и ещё не привязаны)
 * 1С-категорий магазина — для бейджа "новых N".
 */
class NewCount
{
    public function __invoke($_, array $args): int
    {
        $shopId = (int) $args['shop_id'];

        return Category1c::query()
            ->where('shop_id', $shopId)
            ->whereDoesntHave('showcaseCategories')
            ->where('created_at', '>', Carbon::now()->subDays(30))
            ->count();
    }
}
