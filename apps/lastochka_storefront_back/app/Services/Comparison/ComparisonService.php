<?php

declare(strict_types=1);

namespace App\Services\Comparison;

use App\Exceptions\ProductHasNoCategoryException;
use App\Models\Comparison;
use App\Models\ComparisonItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ComparisonService
{
    /**
     * Добавить товар в сравнение пользователя.
     * Лениво создаёт группу `Comparison(user, category)` и идемпотентно — `ComparisonItem`.
     *
     * @return array{comparison: Comparison, item: ComparisonItem, count: int}
     *
     * @throws ProductHasNoCategoryException
     */
    public function addItem(User $user, Product $product): array
    {
        $product->loadMissing('categories');

        if ($product->categories->isEmpty()) {
            throw new ProductHasNoCategoryException();
        }

        return DB::transaction(function () use ($user, $product): array {
            $comparison = Comparison::firstOrCreate([
                'user_id' => $user->id,
                'category_id' => $product->categories->first()?->id,
            ]);

            $item = ComparisonItem::firstOrCreate([
                'comparison_id' => $comparison->id,
                'product_id' => $product->id,
            ]);

            $count = $comparison->items()->count();

            return [
                'comparison' => $comparison,
                'item' => $item,
                'count' => $count,
            ];
        });
    }

    /**
     * Удалить товар из сравнения пользователя по product_id.
     * Если после удаления группа пустая — удаляет и Comparison.
     *
     * @return bool true если что-то удалено, false если не нашли.
     */
    public function removeItemByProduct(User $user, int $productId): bool
    {
        return DB::transaction(function () use ($user, $productId): bool {
            $item = ComparisonItem::query()
                ->whereHas('comparison', fn ($q) => $q->where('user_id', $user->id))
                ->where('product_id', $productId)
                ->first();

            if ($item === null) {
                return false;
            }

            $comparison = $item->comparison;
            $item->delete();

            if ($comparison !== null && $comparison->items()->count() === 0) {
                $comparison->delete();
            }

            return true;
        });
    }

    /**
     * Удалить группу сравнения пользователя по категории.
     */
    public function clearGroup(User $user, int $categoryId): bool
    {
        $comparison = Comparison::query()
            ->where('user_id', $user->id)
            ->where('category_id', $categoryId)
            ->first();

        if ($comparison === null) {
            return false;
        }

        $comparison->delete();

        return true;
    }
}
