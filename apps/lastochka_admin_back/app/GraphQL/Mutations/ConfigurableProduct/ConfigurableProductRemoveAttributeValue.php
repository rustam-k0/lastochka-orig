<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations\ConfigurableProduct;

use App\Models\Product;
use App\Models\ProductAttribute;
use App\Services\ConfigurableProductService;
use App\Services\ModuleConfigService;
use Illuminate\Support\Facades\DB;

final class ConfigurableProductRemoveAttributeValue
{
    public function __construct(private ConfigurableProductService $service)
    {
    }

    public function __invoke($_, array $args): array
    {
        if (!ModuleConfigService::isConfigurableProductsEnabled()) {
            return [
                'status' => false,
                'message' => 'Фича «Вариативные товары» отключена',
                'deleted_variant_ids' => [],
                'blocked_by_orders' => [],
            ];
        }

        $parent = Product::where('is_configurable', true)->find($args['parent_id']);

        if (!$parent) {
            return [
                'status' => false,
                'message' => 'Вариативный товар не найден',
                'deleted_variant_ids' => [],
                'blocked_by_orders' => [],
            ];
        }

        if (!\Auth::user()->isGlobalScope() && $parent->shop_id !== \Auth::user()->shops()->first()?->shop_id) {
            return [
                'status' => false,
                'message' => 'Нет доступа',
                'deleted_variant_ids' => [],
                'blocked_by_orders' => [],
            ];
        }

        $attributeId = (int) $args['attribute_id'];
        $attributeValueId = (int) $args['attribute_value_id'];

        $variantIds = ProductAttribute::query()
            ->join('products', 'product_attributes.product_id', '=', 'products.id')
            ->where('products.parent_id', $parent->id)
            ->whereNull('products.deleted_at')
            ->where('product_attributes.attribute_id', $attributeId)
            ->where('product_attributes.attribute_value_id', $attributeValueId)
            ->pluck('products.id')
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->all();

        if (empty($variantIds)) {
            return [
                'status' => true,
                'message' => 'Нет вариантов с этим значением',
                'deleted_variant_ids' => [],
                'blocked_by_orders' => [],
            ];
        }

        $blocked = [];
        foreach ($variantIds as $variantId) {
            if ($this->service->hasActiveOrders($variantId)) {
                $blocked[] = $variantId;
            }
        }

        if (!empty($blocked)) {
            return [
                'status' => false,
                'message' => 'Нельзя удалить: варианты в активных заказах',
                'deleted_variant_ids' => [],
                'blocked_by_orders' => $blocked,
            ];
        }

        DB::transaction(function () use ($parent, $variantIds, $attributeId, $attributeValueId) {
            foreach ($variantIds as $variantId) {
                $variant = Product::find($variantId);
                if ($variant) {
                    $variant->delete();
                    event(new \App\Events\ProductUpdated($variant));
                }
            }

            $this->service->logChange(
                (int) $parent->id,
                'remove_attribute_value',
                [
                    'attribute_id' => $attributeId,
                    'attribute_value_id' => $attributeValueId,
                    'deleted_variant_ids' => $variantIds,
                ]
            );

            event(new \App\Events\ProductUpdated($parent));
        });

        return [
            'status' => true,
            'message' => 'OK',
            'deleted_variant_ids' => $variantIds,
            'blocked_by_orders' => [],
        ];
    }
}
