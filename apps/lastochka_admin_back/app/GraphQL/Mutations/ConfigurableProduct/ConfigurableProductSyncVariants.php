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

final class ConfigurableProductSyncVariants
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

        $combinations = $args['combinations_to_create'] ?? [];
        if (empty($combinations)) {
            return ['code' => 422, 'message' => 'Не переданы комбинации для создания'];
        }

        try {
            $result = DB::transaction(function () use ($parent, $combinations) {
                $createdIds = [];

                foreach ($combinations as $combo) {
                    $attrValues = $combo['attribute_values'];
                    $attributeValueIds = array_map(
                        static fn ($av) => (int) $av['attribute_value_id'],
                        $attrValues
                    );

                    $this->service->ensureCombinationUnique((int) $parent->id, $attributeValueIds);

                    $codes = $this->resolveAttributeValueCodes($attributeValueIds);
                    $generatedSku = $this->service->generateVariantSku($parent, $codes);
                    $finalSku = $combo['sku'] ?? $generatedSku;

                    $variant = Product::create([
                        'title' => $parent->title . ' — ' . implode(' / ', $codes),
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

                    foreach ($attrValues as $attrValue) {
                        ProductAttribute::create([
                            'product_id' => $variant->id,
                            'attribute_id' => $attrValue['attribute_id'],
                            'attribute_value_id' => $attrValue['attribute_value_id'],
                        ]);
                    }

                    if (isset($combo['price'])) {
                        Price::upsertForShop(
                            $variant->id,
                            $parent->shop_id,
                            Type::PRICE_DEFAULT,
                            ['value' => (float) $combo['price'], 'step' => 1]
                        );
                    }

                    if (isset($combo['stock']) && class_exists(Remain::class)) {
                        Remain::upsertQuantity($variant->id, $parent->shop_id, (int) $combo['stock']);
                    }

                    $createdIds[] = $variant->id;
                    event(new \App\Events\ProductUpdated($variant));
                }

                $this->service->logChange(
                    (int) $parent->id,
                    'sync_variants',
                    [
                        'variant_ids' => $createdIds,
                        'count' => count($createdIds),
                    ]
                );

                event(new \App\Events\ProductUpdated($parent));

                return $createdIds;
            });
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ['code' => 422, 'message' => implode('; ', array_merge(...array_values($e->errors())))];
        }

        return [
            'code' => 200,
            'message' => 'OK',
            'variant_ids' => $result,
        ];
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
