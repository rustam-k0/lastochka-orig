<?php

namespace App\GraphQL\Mutations\Category;

use Illuminate\Support\Facades\Log;

final class SetCategoriesStockMode
{
    public function __invoke($_, array $args): int
    {
        $single = app(SetCategoryStockMode::class);
        $count = 0;
        foreach ((array) $args['ids'] as $id) {
            try {
                $single(null, [
                    'category_id' => (int) $id,
                    'stock_mode' => $args['stock_mode'],
                    'fixed_stock_quantity' => $args['fixed_stock_quantity'] ?? null,
                    'fixed_stock_unit_type_id' => $args['fixed_stock_unit_type_id'] ?? null,
                ]);
                $count++;
            } catch (\Throwable $e) {
                Log::error('Bulk SetCategoriesStockMode failed for category', [
                    'category_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }
}
