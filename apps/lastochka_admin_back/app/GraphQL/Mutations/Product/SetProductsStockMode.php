<?php

namespace App\GraphQL\Mutations\Product;

use App\Models\Product;
use App\Models\StockModeChange;
use App\Services\Stock\StockMode;
use App\Services\Stock\StockOverrideService;
use Illuminate\Support\Facades\Auth;

final class SetProductsStockMode
{
    public function __invoke($_, array $args): int
    {
        $mode = $args['stock_mode'] ?? null; // null = «по категории»
        if ($mode !== null && !in_array($mode, StockMode::productValues(), true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['stock_mode' => ['Недопустимый режим остатков товара.']]);
        }
        $service = app(StockOverrideService::class);

        // Фиксированный лимит: единица из семейства каждого товара. Несовпадение
        // у части выборки отклоняет действие целиком — до любых записей.
        if ($mode === StockMode::FIXED) {
            $misfits = Product::whereIn('id', (array) $args['ids'])->get()
                ->reject(fn (Product $p) => $service->fixedUnitFits($p, $args['fixed_stock_unit_type_id'] ?? null))
                ->pluck('title');
            if ($misfits->isNotEmpty()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'fixed_stock_unit_type_id' => ['Единица лимита не подходит товарам: ' . $misfits->implode(', ') . '.'],
                ]);
            }
        }

        $count = 0;
        foreach ((array) $args['ids'] as $id) {
            $product = Product::find($id);
            if (!$product) {
                continue;
            }
            $old = $product->stock_mode;
            $product->forceFill([
                'stock_mode' => $mode,
                'fixed_stock_quantity' => $mode === StockMode::FIXED ? ($args['fixed_stock_quantity'] ?? null) : null,
                'fixed_stock_unit_type_id' => $mode === StockMode::FIXED ? ($args['fixed_stock_unit_type_id'] ?? null) : null,
            ])->save();
            if ((string) $old !== (string) $mode) {
                StockModeChange::create(['entity_type' => 'product', 'entity_id' => $product->id, 'old_mode' => $old, 'new_mode' => $mode, 'new_fixed' => $mode === StockMode::FIXED ? ($args['fixed_stock_quantity'] ?? null) : null, 'user_id' => Auth::id()]);
            }
            $service->applyToProduct($product->fresh());
            $count++;
        }

        return $count;
    }
}
