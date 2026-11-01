<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations\Category1cMutations;

use App\Models\Category1c;
use Illuminate\Support\Facades\DB;

/**
 * Массово отвязать 1С-категории от ВСЕХ витринных (полная очистка pivot для каждой 1С).
 *
 * `linked_at = null`. `last_synced_at` НЕ трогаем — это история прошлой синхры.
 */
final class UnlinkFromShowcase
{
    /**
     * @param  array{category_1c_ids: list<int>}  $args
     * @return list<Category1c>
     */
    public function __invoke($_, array $args): array
    {
        $ids = array_values(array_unique(array_map('intval', $args['category_1c_ids'])));

        $cats = Category1c::whereIn('id', $ids)->get();

        DB::transaction(function () use ($cats) {
            foreach ($cats as $cat) {
                $cat->showcaseCategories()->detach();
                $cat->forceFill(['linked_at' => null])->save();
            }
        });

        return Category1c::whereIn('id', $ids)->get()->all();
    }
}
