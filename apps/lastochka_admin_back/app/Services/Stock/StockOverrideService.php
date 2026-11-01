<?php

namespace App\Services\Stock;

use App\Events\ProductUpdated;
use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Services\Quantum\UnitFamily;
use App\Support\StockUnits;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class StockOverrideService
{
    /**
     * Эффективный режим остатков товара.
     * Приоритет: товар (если задан) → витринные категории (с наследованием от
     * родителей дерева) → shops.ignore_stock → normal.
     *
     * @return array{mode: string, fixed: ?int, fixed_unit_type_id: ?int}  fixed — в БАЗОВОЙ единице остатка (г/шт).
     */
    public function resolve(Product $product): array
    {
        // 1. Товар — явный режим (ignore/fixed) приоритетнее всего.
        if ($product->stock_mode === StockMode::IGNORE) {
            return ['mode' => StockMode::IGNORE, 'fixed' => null, 'fixed_unit_type_id' => null];
        }
        if ($product->stock_mode === StockMode::FIXED) {
            return ['mode' => StockMode::FIXED, 'fixed' => $this->productFixedInBaseUnit($product), 'fixed_unit_type_id' => null];
        }

        // 2-4. Категории → магазин → стандарт (общая логика с предпросмотром
        // ещё не сохранённого товара, см. resolveForPreview()).
        return $this->resolveForPreview(
            $product->categories()->pluck('categories.id')->all(),
            $product->shop_id,
            $product->base_unit_type_id ?? $product->info_weight_type_id
        );
    }

    /**
     * Предпросмотр эффективного режима остатков ДО сохранения товара:
     * категории (с наследованием от родителей) → shops.ignore_stock → normal.
     * Не учитывает собственный stock_mode товара — для ещё несохранённого
     * товара его не существует (используется только для незаданного/normal
     * состояния, откуда и вызывается resolve()).
     *
     * @param int[] $categoryIds
     * @return array{mode: string, fixed: ?int, fixed_unit_type_id: ?int}
     */
    public function resolveForPreview(array $categoryIds, ?int $shopId, ?int $unitTypeId): array
    {
        $fromCategory = $this->resolveFromCategoryIds($categoryIds, $unitTypeId);
        if ($fromCategory !== null) {
            return $fromCategory;
        }

        $shop = $shopId ? Shop::find($shopId) : null;
        if ($shop && $shop->ignore_stock) {
            return ['mode' => StockMode::IGNORE, 'fixed' => null, 'fixed_unit_type_id' => null];
        }

        return ['mode' => StockMode::NORMAL, 'fixed' => null, 'fixed_unit_type_id' => null];
    }

    /**
     * Материализует effective_stock_mode / effective_fixed_stock в products.
     * Если значение изменилось — публикует ProductUpdated (если $dispatchEvent=true).
     *
     * @return bool true если effective изменился
     */
    public function applyToProduct(Product $product, bool $dispatchEvent = true): bool
    {
        $resolved = $this->resolve($product);

        $newMode = $resolved['mode'];
        $newFixed = $resolved['fixed'];

        $changed = (string) $product->effective_stock_mode !== (string) $newMode
            || (int) ($product->effective_fixed_stock ?? 0) !== (int) ($newFixed ?? 0);

        Product::withoutEvents(function () use ($product, $newMode, $newFixed) {
            $product->forceFill([
                'effective_stock_mode'  => $newMode,
                'effective_fixed_stock' => $newFixed,
            ])->save();
        });

        if ($changed && $dispatchEvent) {
            event(new ProductUpdated($product->fresh()));
        }

        return $changed;
    }

    /**
     * Единица фиксированного лимита подходит товару: то же семейство, что у его
     * остатка (масса — граммы, объём — мл, штуки — штуки). На уровне товара это
     * не проверялось: «5 штук» у весового становились 5 г (effective_fixed_stock
     * в наименьших единицах), товар фактически пропадал из наличия.
     */
    public function fixedUnitFits(Product $product, mixed $unitTypeId): bool
    {
        $family = UnitFamily::family($unitTypeId !== null ? (int) $unitTypeId : null);

        return $family !== null && $family === UnitFamily::family(StockUnits::stockUnit($product));
    }

    /** @throws ValidationException */
    public function assertFixedUnitFits(Product $product, mixed $unitTypeId): void
    {
        if (!$this->fixedUnitFits($product, $unitTypeId)) {
            throw ValidationException::withMessages([
                'fixed_stock_unit_type_id' => ['Единица лимита не подходит товару: ' . self::fixedUnitHint($product) . '.'],
            ]);
        }
    }

    private static function fixedUnitHint(Product $product): string
    {
        return match (UnitFamily::family(StockUnits::stockUnit($product))) {
            UnitFamily::MASS => 'для весового — граммы',
            UnitFamily::VOLUME => 'для объёмного — миллилитры',
            default => 'для штучного — штуки',
        };
    }

    /** fixed-лимит товара в базовой единице остатка. */
    private function productFixedInBaseUnit(Product $product): ?int
    {
        $qty = (int) ($product->fixed_stock_quantity ?? 0);
        if ($qty <= 0) {
            return null;
        }

        return $qty * UnitFamily::sizeInSmallest($product->fixed_stock_unit_type_id);
    }

    /**
     * Режим из категорий (climb по дереву от каждой к первому предку с
     * stock_mode != normal). Среди найденных: fixed строже ignore; при
     * нескольких fixed — минимальный лимит. fixed применяется только при
     * совпадении unit-семейства (масса/объём/шт, см. UnitFamily).
     *
     * @param int[] $categoryIds
     * @return array{mode: string, fixed: ?int, fixed_unit_type_id: ?int}|null
     */
    private function resolveFromCategoryIds(array $categoryIds, ?int $unitTypeId): ?array
    {
        $family = UnitFamily::family($unitTypeId);

        $best = null; // ['mode'=>..., 'fixed'=>..., 'fixed_unit_type_id'=>...]
        foreach ($categoryIds as $categoryId) {
            $category = Category::find($categoryId);
            if ($category === null) {
                continue;
            }
            $node = $this->climbToConfigured($category);
            if ($node === null) {
                continue;
            }

            if ($node->stock_mode === StockMode::FIXED) {
                // fixed только для совпадающего семейства.
                $unitFamily = UnitFamily::family($node->fixed_stock_unit_type_id);
                if ($family === null || $unitFamily !== $family) {
                    continue;
                }
                $fixed = (int) ($node->fixed_stock_quantity ?? 0) * UnitFamily::sizeInSmallest($node->fixed_stock_unit_type_id);
                if ($fixed <= 0) {
                    continue;
                }
                if ($best === null || $best['mode'] !== StockMode::FIXED || $fixed < $best['fixed']) {
                    $best = ['mode' => StockMode::FIXED, 'fixed' => $fixed, 'fixed_unit_type_id' => UnitFamily::smallestUnit($family)];
                }
            } elseif ($node->stock_mode === StockMode::IGNORE) {
                if ($best === null) {
                    $best = ['mode' => StockMode::IGNORE, 'fixed' => null, 'fixed_unit_type_id' => null];
                }
            }
        }

        return $best;
    }

    /**
     * Пересчитывает effective_stock_mode / effective_fixed_stock у всех товаров
     * категории и всех её подкатегорий (рекурсивно).
     *
     * @return int число изменённых товаров
     */
    public function recalcCategory(Category $category): int
    {
        $count = 0;
        foreach ($this->categoryWithDescendantIds($category) as $catId) {
            $cat = Category::find($catId);
            if ($cat === null) {
                continue;
            }
            $cat->products()->chunkById(200, function ($products) use (&$count) {
                foreach ($products as $product) {
                    try {
                        if ($this->applyToProduct($product)) {
                            $count++;
                        }
                    } catch (\Throwable $e) {
                        Log::error('Stock override recalc failed', ['product_id' => $product->id, 'error' => $e->getMessage()]);
                    }
                }
            });
        }

        return $count;
    }

    /** id категории + всех её потомков (BFS по дереву). */
    private function categoryWithDescendantIds(Category $category): array
    {
        $ids = [$category->id];
        $queue = [$category->id];
        $guard = 0;
        while ($queue !== [] && $guard++ < 1000) {
            $parentId = array_shift($queue);
            $childIds = Category::where('parent_id', $parentId)->pluck('id')->all();
            foreach ($childIds as $cid) {
                if (!in_array($cid, $ids, true)) {
                    $ids[] = $cid;
                    $queue[] = $cid;
                }
            }
        }

        return $ids;
    }

    /** Подняться по дереву от категории к первому предку с stock_mode != normal. */
    private function climbToConfigured(Category $category): ?Category
    {
        $current = $category;
        $guard = 0;
        while ($current !== null && $guard++ < 10) {
            if ($current->stock_mode !== null && $current->stock_mode !== StockMode::NORMAL) {
                return $current;
            }
            $current = $current->parent_id ? Category::find($current->parent_id) : null;
        }

        return null;
    }
}
