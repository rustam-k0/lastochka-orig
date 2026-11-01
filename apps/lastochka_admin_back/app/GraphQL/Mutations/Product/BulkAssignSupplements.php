<?php

namespace App\GraphQL\Mutations\Product;

use App\Events\ProductUpdated;
use App\Models\Product;
use App\Models\Supplement;
use App\Models\SupplementValue;
use Illuminate\Support\Facades\DB;

class BulkAssignSupplements
{
    /**
     * Массово назначает группу добавки (с её values) на список товаров.
     *
     * Идемпотентность:
     *  - Supplement: updateOrCreate по (product_id, title). При существующей —
     *    обновляет type/is_required.
     *  - SupplementValue: updateOrCreate по (supplement_id, title).
     *    Лишние values (не пришедшие в запросе) удаляются.
     *
     * RabbitMQ: после изменений публикует ProductUpdated для каждого товара
     * (НЕ через touch — Product::touch не триггерит outbox в admin_back).
     */
    public function __invoke($_, array $args): array
    {
        $productIds     = $args['product_ids'];
        $supplementData = $args['supplement'];

        DB::transaction(function () use ($productIds, $supplementData) {
            $products = Product::whereIn('id', $productIds)->get();

            foreach ($products as $product) {
                $this->assignSupplementToProduct($product, $supplementData);

                $product->refresh();
                $product->load('supplements.values');

                event(new ProductUpdated($product));
            }
        });

        return [
            'code'    => 200,
            'message' => 'Добавки назначены',
        ];
    }

    private function assignSupplementToProduct(Product $product, array $supplementData): void
    {
        /** @var Supplement $supplement */
        $supplement = $product->supplements()->updateOrCreate(
            ['title' => $supplementData['title']],
            [
                'type'        => $supplementData['type']        ?? Supplement::TYPE_MANY,
                'is_required' => $supplementData['is_required'] ?? false,
            ]
        );

        $valueIds = [];

        foreach ($supplementData['values'] ?? [] as $value) {
            /** @var SupplementValue $sv */
            $sv = $supplement->values()->updateOrCreate(
                ['title' => $value['title']],
                [
                    'price'        => $value['price']        ?? null,
                    'product_id'   => $value['product_id']   ?? null,
                    'max_quantity' => $value['max_quantity']  ?? null,
                    'track_stock'  => $value['track_stock']  ?? null,
                    'position'     => $value['position']      ?? 0,
                ]
            );

            $valueIds[] = $sv->id;
        }

        // Удаляем values, которых больше нет в запросе
        if (!empty($valueIds)) {
            $supplement->values()->whereNotIn('id', $valueIds)->delete();
        } else {
            $supplement->values()->delete();
        }
    }
}
