<?php

namespace App\GraphQL\Mutations\ConfigurableProduct;

use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Status;
use App\Services\ConfigurableProductService;
use App\Services\ModuleConfigService;
use Illuminate\Support\Facades\DB;

final class ConfigurableProductGenerateVariants
{
    public function __construct(private ConfigurableProductService $service)
    {
    }

    public function __invoke($_, array $args): array
    {
        if (!ModuleConfigService::isConfigurableProductsEnabled()) {
            return ['code' => 403, 'message' => 'Фича «Вариативные товары» отключена'];
        }

        $parent = Product::find($args['parent_id']);

        if (!$parent || !$parent->is_configurable) {
            return ['code' => 404, 'message' => 'Вариативный товар не найден'];
        }

        $shopId = \Auth::user()->isGlobalScope()
            ? $parent->shop_id
            : \Auth::user()->shops()->first()->shop_id;

        if ($parent->shop_id !== $shopId && !\Auth::user()->isGlobalScope()) {
            return ['code' => 403, 'message' => 'Нет доступа'];
        }

        $matrix = $args['matrix'];

        // Check for duplicate combinations in matrix (строгая валидация в рамках матрицы)
        $combinations = [];
        foreach ($matrix as $index => $variant) {
            $key = collect($variant['attribute_values'])
                ->sortBy('attribute_id')
                ->map(fn($av) => $av['attribute_id'] . ':' . $av['attribute_value_id'])
                ->implode('|');
            if (in_array($key, $combinations)) {
                return ['code' => 422, 'message' => 'Дублирование комбинации атрибутов в строке ' . ($index + 1)];
            }
            $combinations[] = $key;
        }

        return DB::transaction(function () use ($parent, $matrix) {
            $createdIds = [];
            $previewRows = [];

            foreach ($matrix as $index => $variant) {
                $attributeValueIds = array_map(
                    static fn ($av) => (int) $av['attribute_value_id'],
                    $variant['attribute_values']
                );

                $this->service->ensureCombinationUnique($parent->id, $attributeValueIds);

                $codes = $this->resolveAttributeValueCodes($attributeValueIds);
                $generatedSku = $this->service->generateVariantSku($parent, $codes);
                $finalSku = $variant['sku'] ?? $generatedSku;

                $title = $variant['title'] ?? $parent->title . ' — Вариант ' . ($index + 1);

                $child = Product::create([
                    'title' => $title,
                    'articul' => $finalSku,
                    'shop_id' => $parent->shop_id,
                    'parent_id' => $parent->id,
                    'is_configurable' => false,
                    'status_id' => Status::CODE_NOT_PUBLISHED,
                ]);

                $categoryIds = $parent->categories()->pluck('categories.id')->toArray();
                $child->categories()->sync($categoryIds);

                foreach ($variant['attribute_values'] as $attrValue) {
                    ProductAttribute::create([
                        'product_id' => $child->id,
                        'attribute_id' => $attrValue['attribute_id'],
                        'attribute_value_id' => $attrValue['attribute_value_id'],
                    ]);
                }

                $createdIds[] = $child->id;
                $previewRows[] = [
                    'variant_id' => $child->id,
                    'sku' => $finalSku,
                    'attribute_value_ids' => $attributeValueIds,
                ];

                event(new \App\Events\ProductUpdated($child));
            }

            $this->service->logChange(
                (int) $parent->id,
                'generate_variants',
                [
                    'variants' => $previewRows,
                    'count' => count($createdIds),
                ]
            );

            $parent->refresh();
            event(new \App\Events\ProductUpdated($parent));

            return [
                'code' => 200,
                'message' => 'OK',
                'variant_ids' => $createdIds,
            ];
        });
    }

    /**
     * @param  list<int>  $attributeValueIds
     * @return list<string>
     */
    private function resolveAttributeValueCodes(array $attributeValueIds): array
    {
        if (empty($attributeValueIds)) {
            return [];
        }

        $codes = AttributeValue::query()
            ->whereIn('id', $attributeValueIds)
            ->pluck('code', 'id')
            ->all();

        $ordered = [];
        foreach ($attributeValueIds as $id) {
            $ordered[] = (string) ($codes[$id] ?? $id);
        }

        return $ordered;
    }
}
