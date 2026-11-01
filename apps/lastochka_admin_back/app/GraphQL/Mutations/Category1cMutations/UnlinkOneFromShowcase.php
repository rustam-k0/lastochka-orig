<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations\Category1cMutations;

use App\Models\Category1c;
use Illuminate\Support\Facades\DB;

/**
 * Точечно отвязать одну витринную категорию от одной 1С-категории.
 *
 * Если после отвязки у 1С больше нет привязок — обнуляем `linked_at`.
 */
final class UnlinkOneFromShowcase
{
    /**
     * @param  array{category_1c_id: int, showcase_category_id: int}  $args
     */
    public function __invoke($_, array $args): Category1c
    {
        $cat1cId    = (int) $args['category_1c_id'];
        $showcaseId = (int) $args['showcase_category_id'];

        $cat = Category1c::findOrFail($cat1cId);

        DB::transaction(function () use ($cat, $showcaseId) {
            $cat->showcaseCategories()->detach($showcaseId);

            if ($cat->showcaseCategories()->count() === 0) {
                $cat->forceFill(['linked_at' => null])->save();
            }
        });

        return $cat->fresh();
    }
}
