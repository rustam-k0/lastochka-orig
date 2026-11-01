<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations\ConfigurableProduct;

use App\Models\AttributeValue;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Remain;
use App\Models\Status;
use App\Models\Type;
use App\Services\ConfigurableProductService;
use App\Services\ModuleConfigService;
use Illuminate\Support\Facades\DB;

final class ConfigurableProductAddVariant
{
    public function __construct(private ConfigurableProductService $service)
    {
    }

    public function __invoke($_, array $args): array
    {
        if (!ModuleConfigService::isConfigurableProductsEnabled()) {
            return ['code' => 403, 'message' => 'Фича «Вариативные товары» отключена'];
        }

        $parent = Product::where('is_configurable', true)->find($args['parent_id']);

        if (!$parent) {
            return ['code' => 404, 'message' => 'Вариативный товар не найден'];
        }

        if (!\Auth::user()->isGlobalScope() && $parent->shop_id !== \Auth::user()->shops()->first()?->shop_id) {
            return ['code' => 403, 'message' => 'Нет доступа'];
        }

        $attributeValues = $args['attribute_values'] ?? [];
        if (empty($attributeValues)) {
            return ['code' => 422, 'message' => 'Не переданы значения атрибутов'];
        }

        $attributeValueIds = array_map(static fn ($av) => (int) $av['attribute_value_id'], $attributeValues);

        // Проверка что все вариативные атрибуты parent'а покрыты
        $configurableAttributeIds = $this->resolveConfigurableAttributeIdsForParent($parent);
        $passedAttributeIds = array_map(static fn ($av) => (int) $av['attribute_id'], $attributeValues);

        if (!empty($configurableAttributeIds)) {
            $missing = array_diff($configurableAttributeIds, $passedAttributeIds);
            if (!empty($missing)) {
                return [
                    'code' => 422,
                    'message' => 'Не заполнены вариативные атрибуты: ' . implode(', ', $missing),
                ];
            }
        }

        try {
            $this->service->ensureCombinationUnique((int) $parent->id, $attributeValueIds);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ['code' => 422, 'message' => implode('; ', array_merge(...array_values($e->errors())))];
        }

        $variant = DB::transaction(function () use ($parent, $args, $attributeValues, $attributeValueIds) {
            $codes = $this->resolveAttributeValueCodes($attributeValueIds);
            $generatedSku = $this->service->generateVariantSku($parent, $codes);
            $finalSku = $args['sku'] ?? $generatedSku;

            $variant = Product::create([
                'title' => $args['title'] ?? $parent->title . ' — новый вариант',
                'articul' => $finalSku,
                'shop_id' => $parent->shop_id,
                'parent_id' => $parent->id,
                'is_configurable' => false,
                'status_id' => Status::CODE_NOT_PUBLISHED,
            ]);

            $categoryIds = $parent->categories()->pluck('categories.id')->toArray();
            if (!empty($categoryIds)) {
                $variant->categories()->sync($categoryIds);
            }

            foreach ($attributeValues as $attrValue) {
                ProductAttribute::create([
                    'product_id' => $variant->id,
                    'attribute_id' => $attrValue['attribute_id'],
                    'attribute_value_id' => $attrValue['attribute_value_id'],
                ]);
            }

            if (isset($args['price'])) {
                Price::upsertForShop(
                    $variant->id,
                    $parent->shop_id,
                    Type::PRICE_DEFAULT,
                    ['value' => (float) $args['price'], 'step' => 1]
                );
            }

            if (isset($args['stock']) && class_exists(Remain::class)) {
                Remain::upsertQuantity($variant->id, $parent->shop_id, (int) $args['stock']);
            }

            if (isset($args['img'])) {
                $variant->saveImg($args['img']);
            }
            if (isset($args['images'])) {
                foreach ($args['images'] as $img) {
                    if ($img) {
                        $variant->saveImg($img, Status::CODE_SLIDER_IMG);
                    }
                }
            }

            $this->service->logChange(
                (int) $parent->id,
                'add_variant',
                [
                    'variant_id' => $variant->id,
                    'sku' => $finalSku,
                    'attribute_value_ids' => $attributeValueIds,
                    'price' => $args['price'] ?? null,
                    'stock' => $args['stock'] ?? null,
                ]
            );

            event(new \App\Events\ProductUpdated($variant));
            event(new \App\Events\ProductUpdated($parent));

            return $variant;
        });

        return [
            'code' => 200,
            'message' => 'OK',
            'variant_id' => $variant->id,
        ];
    }

    /**
     * @return list<int>
     */
    private function resolveConfigurableAttributeIdsForParent(Product $parent): array
    {
        $parent->load('children.attributes');

        $ids = [];
        foreach ($parent->children as $child) {
            foreach ($child->attributes as $pa) {
                if ($pa->attribute_value_id !== null) {
                    $ids[(int) $pa->attribute_id] = true;
                }
            }
        }

        return array_map('intval', array_keys($ids));
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
