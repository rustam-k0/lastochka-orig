<?php

namespace App\GraphQL\Queries\ConfigurableProduct;

use App\Models\AttributeFamily;
use App\Models\Price;
use App\Models\Product;
use App\Models\Remain;
use App\Models\Type;

final class ConfigurableProductItem
{
    public function __invoke($_, array $args): ?Product
    {
        $product = Product::with([
            'categories.attributeFamily.attributes' => function ($q) {
                $q->wherePivot('is_configurable', true);
            },
            'children.defaultPrice',
            'children.remains',
            'children.attributes.attribute',
            'children.attributes.attributeValue',
            'img',
            'images',
            'brand',
        ])->find($args['id']);

        if (!$product || !$product->is_configurable) {
            return null;
        }

        // Check shop access
        $user = \Auth::user();
        if (!$user->isGlobalScope()) {
            $userShopId = $user->shops()->first()?->shop_id;
            if ($product->shop_id !== $userShopId) {
                return null;
            }
        }

        return $product;
    }

    public function configurableAttributes(Product $product): array
    {
        $category = $product->categories->first();
        if (!$category || !$category->attribute_family_id) {
            return [];
        }

        $family = AttributeFamily::with(['attributes' => function ($q) {
            $q->wherePivot('is_configurable', true)
              ->where('type_id', Type::ATTRIBUTE_SELECT_ONE);
        }])->find($category->attribute_family_id);

        if (!$family) {
            return [];
        }

        return $family->attributes->map(function ($attr) {
            return [
                'id' => $attr->pivot->id,
                'attribute_id' => $attr->id,
                'attribute' => $attr,
                'is_configurable' => true,
            ];
        })->toArray();
    }

    public function computedPriceMin(Product $product): ?int
    {
        return Price::whereIn('product_id', $product->children()->pluck('id'))
            ->where('type_id', Type::PRICE_DEFAULT)
            ->min('value');
    }

    public function computedPriceMax(Product $product): ?int
    {
        return Price::whereIn('product_id', $product->children()->pluck('id'))
            ->where('type_id', Type::PRICE_DEFAULT)
            ->max('value');
    }

    public function computedQuantity(Product $product): int
    {
        return (int) Remain::whereIn('product_id', $product->children()->pluck('id'))
            ->sum('quantity');
    }
}
