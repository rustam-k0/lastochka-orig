<?php

declare(strict_types=1);

namespace App\GraphQL\Queries\ConfigurableProduct;

use App\Models\ProductAttribute;
use App\Services\ConfigurableProductService;
use App\Services\ModuleConfigService;

final class ConfigurableProductVariantsByAttribute
{
    public function __construct(private ConfigurableProductService $service)
    {
    }

    public function __invoke($_, array $args): array
    {
        if (!ModuleConfigService::isConfigurableProductsEnabled()) {
            return [];
        }

        $parentId = (int) $args['parent_id'];
        $attributeId = (int) $args['attribute_id'];
        $attributeValueId = (int) $args['attribute_value_id'];

        $variantIds = ProductAttribute::query()
            ->join('products', 'product_attributes.product_id', '=', 'products.id')
            ->where('products.parent_id', $parentId)
            ->whereNull('products.deleted_at')
            ->where('product_attributes.attribute_id', $attributeId)
            ->where('product_attributes.attribute_value_id', $attributeValueId)
            ->pluck('products.id')
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->all();

        $variantIds = array_values($variantIds);

        $blockedByOrders = [];
        foreach ($variantIds as $variantId) {
            if ($this->service->hasActiveOrders($variantId)) {
                $blockedByOrders[] = $variantId;
            }
        }

        return [
            'variant_ids' => $variantIds,
            'blocked_by_orders' => $blockedByOrders,
        ];
    }
}
