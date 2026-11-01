<?php

declare(strict_types=1);

namespace App\GraphQL\Queries\ConfigurableProduct;

use App\Models\Product;
use App\Services\ConfigurableProductService;
use App\Services\ModuleConfigService;

final class ConfigurableProductPreviewNewCombinations
{
    public function __construct(private ConfigurableProductService $service)
    {
    }

    public function __invoke($_, array $args): array
    {
        if (!ModuleConfigService::isConfigurableProductsEnabled()) {
            return [];
        }

        $parent = Product::where('is_configurable', true)->find($args['parent_id']);
        if (!$parent) {
            return [];
        }

        return $this->service->previewNewCombinations(
            $parent,
            (int) $args['attribute_id'],
            (int) $args['attribute_value_id']
        );
    }
}
