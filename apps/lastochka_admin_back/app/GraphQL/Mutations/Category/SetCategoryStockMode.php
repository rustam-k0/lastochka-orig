<?php

namespace App\GraphQL\Mutations\Category;

use App\Models\Category;
use App\Models\StockModeChange;
use App\Services\Quantum\UnitFamily;
use App\Services\Stock\StockMode;
use App\Services\Stock\StockOverrideService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SetCategoryStockMode
{
    public const MAX_FIXED_MASS = 100000; // г/мл
    public const MAX_FIXED_PIECE = 10000;  // шт

    public function __invoke($_, array $args): Category
    {
        $category = Category::find($args['category_id']);
        if (!$category) {
            throw ValidationException::withMessages(['category_id' => ['Категория не найдена.']]);
        }

        $mode = $args['stock_mode'];
        if (!in_array($mode, StockMode::categoryValues(), true)) {
            throw ValidationException::withMessages(['stock_mode' => ['Недопустимый режим остатков.']]);
        }

        $fixedQty = null;
        $fixedUnit = null;
        if ($mode === StockMode::FIXED) {
            $fixedQty = (int) ($args['fixed_stock_quantity'] ?? 0);
            $fixedUnit = $args['fixed_stock_unit_type_id'] ?? null;
            if ($fixedUnit === null || !in_array((int) $fixedUnit, UnitFamily::allowedQuantumUnits(), true)) {
                throw ValidationException::withMessages(['fixed_stock_unit_type_id' => ['Единица должна быть: грамм или штука.']]);
            }
            $max = (int) $fixedUnit === \App\Models\Type::INFO_WEIGHT_PIECE ? self::MAX_FIXED_PIECE : self::MAX_FIXED_MASS;
            if ($fixedQty < 1 || $fixedQty > $max) {
                throw ValidationException::withMessages(['fixed_stock_quantity' => [sprintf('Лимит должен быть от 1 до %d.', $max)]]);
            }
        }

        $oldMode = $category->stock_mode;
        $oldFixed = $category->fixed_stock_quantity;

        DB::transaction(function () use ($category, $mode, $fixedQty, $fixedUnit, $oldMode, $oldFixed) {
            $category->forceFill([
                'stock_mode' => $mode,
                'fixed_stock_quantity' => $fixedQty,
                'fixed_stock_unit_type_id' => $fixedUnit,
            ])->save();

            StockModeChange::create([
                'entity_type' => 'category',
                'entity_id' => $category->id,
                'old_mode' => $oldMode,
                'new_mode' => $mode,
                'old_fixed' => $oldFixed,
                'new_fixed' => $fixedQty,
                'user_id' => Auth::id(),
            ]);
        });

        app(StockOverrideService::class)->recalcCategory($category->fresh());

        return $category->fresh();
    }
}
