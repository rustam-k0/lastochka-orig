<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations\ConfigurableProduct;

use App\Models\Price;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Remain;
use App\Models\Status;
use App\Models\Type;
use App\Services\ConfigurableProductService;
use App\Services\ModuleConfigService;
use Illuminate\Support\Facades\DB;

final class ConfigurableProductUpdateVariant
{
    public function __construct(private ConfigurableProductService $service)
    {
    }

    public function __invoke($_, array $args): array
    {
        if (!ModuleConfigService::isConfigurableProductsEnabled()) {
            return ['code' => 403, 'message' => 'Фича «Вариативные товары» отключена'];
        }

        $variant = Product::whereNotNull('parent_id')->find($args['variant_id']);

        if (!$variant) {
            return ['code' => 404, 'message' => 'Вариант не найден'];
        }

        if (!\Auth::user()->isGlobalScope() && $variant->shop_id !== \Auth::user()->shops()->first()?->shop_id) {
            return ['code' => 403, 'message' => 'Нет доступа'];
        }

        $parentId = (int) $variant->parent_id;

        try {
            return DB::transaction(function () use ($variant, $args, $parentId) {
                $before = [
                    'title' => $variant->title,
                    'articul' => $variant->articul,
                    'price' => optional($variant->defaultPrice)->value,
                ];

                $updateData = array_filter([
                    'title' => $args['title'] ?? null,
                    'articul' => $args['sku'] ?? null,
                ], fn ($v) => $v !== null);

                if (!empty($updateData)) {
                    $variant->update($updateData);
                }

                if (array_key_exists('attribute_values', $args) && is_array($args['attribute_values'])) {
                    $attrValues = $args['attribute_values'];
                    $ids = array_map(static fn ($av) => (int) $av['attribute_value_id'], $attrValues);

                    $this->service->ensureCombinationUnique(
                        $parentId,
                        $ids,
                        (int) $variant->id
                    );

                    // Полностью пересоздаём ProductAttribute для переданных атрибутов.
                    $passedAttrIds = array_map(static fn ($av) => (int) $av['attribute_id'], $attrValues);
                    ProductAttribute::where('product_id', $variant->id)
                        ->whereIn('attribute_id', $passedAttrIds)
                        ->delete();

                    foreach ($attrValues as $attrValue) {
                        ProductAttribute::create([
                            'product_id' => $variant->id,
                            'attribute_id' => $attrValue['attribute_id'],
                            'attribute_value_id' => $attrValue['attribute_value_id'],
                        ]);
                    }
                }

                if (array_key_exists('price', $args) && $args['price'] !== null) {
                    Price::upsertForShop(
                        $variant->id,
                        $variant->shop_id,
                        Type::PRICE_DEFAULT,
                        ['value' => (float) $args['price'], 'step' => 1]
                    );
                }

                if (array_key_exists('stock', $args) && $args['stock'] !== null && class_exists(Remain::class)) {
                    Remain::upsertQuantity($variant->id, $variant->shop_id, (int) $args['stock']);
                }

                if (array_key_exists('img', $args)) {
                    if ($args['img'] && !$variant->joinImg) {
                        $variant->saveImg($args['img']);
                    } else {
                        $variant->updateImg($variant->joinImg, $args['img'] ?? null);
                    }
                }

                if (array_key_exists('images', $args)) {
                    if (count($variant->joinImages)) {
                        $variant->updateManyImages($variant->joinImages(), $args['images'] ?? []);
                    } elseif ($args['images']) {
                        foreach ($args['images'] as $img) {
                            if ($img) {
                                $variant->saveImg($img, Status::CODE_SLIDER_IMG);
                            }
                        }
                    }
                }

                $variant->refresh();

                $this->service->logChange(
                    (int) $variant->id,
                    'update_variant',
                    [
                        'variant_id' => $variant->id,
                        'parent_id' => $parentId,
                        'before' => $before,
                        'after' => [
                            'title' => $variant->title,
                            'articul' => $variant->articul,
                            'price' => optional($variant->defaultPrice)->value,
                        ],
                    ]
                );

                event(new \App\Events\ProductUpdated($variant));

                return ['code' => 200, 'message' => 'OK'];
            });
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ['code' => 422, 'message' => implode('; ', array_merge(...array_values($e->errors())))];
        }
    }
}
