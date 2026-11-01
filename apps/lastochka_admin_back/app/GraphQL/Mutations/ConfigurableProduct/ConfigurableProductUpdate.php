<?php

namespace App\GraphQL\Mutations\ConfigurableProduct;

use App\Models\Product;
use App\Models\Status;
use App\Services\ConfigurableProductService;
use App\Services\ModuleConfigService;
use Illuminate\Support\Facades\DB;

final class ConfigurableProductUpdate
{
    public function __construct(private ConfigurableProductService $service)
    {
    }

    public function __invoke($_, array $args): array
    {
        if (!ModuleConfigService::isConfigurableProductsEnabled()) {
            return ['code' => 403, 'message' => 'Фича «Вариативные товары» отключена'];
        }

        $parent = Product::find($args['id']);

        if (!$parent || !$parent->is_configurable) {
            return ['code' => 404, 'message' => 'Вариативный товар не найден'];
        }

        $shopId = \Auth::user()->isGlobalScope()
            ? $parent->shop_id
            : \Auth::user()->shops()->first()->shop_id;

        if ($parent->shop_id !== $shopId && !\Auth::user()->isGlobalScope()) {
            return ['code' => 403, 'message' => 'Нет доступа'];
        }

        // Запрещаем смену parent_id и is_configurable через эту мутацию.
        if (array_key_exists('parent_id', $args) || array_key_exists('is_configurable', $args)) {
            return ['code' => 422, 'message' => 'Смена parent_id / is_configurable через Update недопустима'];
        }

        return DB::transaction(function () use ($parent, $args, $shopId) {
            $before = [
                'title' => $parent->title,
                'description' => $parent->description,
            ];

            $updateData = array_filter([
                'title' => $args['title'] ?? null,
                'description' => $args['description'] ?? null,
                'meta_title' => $args['meta_title'] ?? null,
                'meta_description' => $args['meta_description'] ?? null,
                'meta_keywords' => $args['meta_keywords'] ?? null,
                'brand_id' => $args['brand_id'] ?? null,
                'video_path' => $args['video_path'] ?? null,
            ], fn($v) => $v !== null);

            if (!empty($updateData)) {
                $parent->update($updateData);
            }

            if (isset($args['category_id'])) {
                $parent->categories()->sync([$args['category_id']]);
            }

            if (array_key_exists('img', $args)) {
                if ($args['img'] && !$parent->joinImg) {
                    $parent->saveImg($args['img']);
                } else {
                    $parent->updateImg($parent->joinImg, $args['img'] ?? null);
                }
            }

            if (array_key_exists('images', $args)) {
                if (count($parent->joinImages)) {
                    $parent->updateManyImages($parent->joinImages(), $args['images'] ?? []);
                } elseif ($args['images']) {
                    foreach ($args['images'] as $img) {
                        if ($img) {
                            $parent->saveImg($img, Status::CODE_SLIDER_IMG);
                        }
                    }
                }
            }

            $childrenAdded = [];
            if (!empty($args['children_add'])) {
                $newChildren = Product::whereIn('id', $args['children_add'])
                    ->where('shop_id', $shopId)
                    ->whereNull('parent_id')
                    ->where('is_configurable', false)
                    ->lockForUpdate()
                    ->get();

                foreach ($newChildren as $child) {
                    $child->update(['parent_id' => $parent->id]);
                    $childrenAdded[] = $child->id;
                    event(new \App\Events\ProductUpdated($child));
                }
            }

            $childrenRemoved = [];
            if (!empty($args['children_remove'])) {
                $childrenRemoved = Product::whereIn('id', $args['children_remove'])
                    ->where('parent_id', $parent->id)
                    ->pluck('id')
                    ->all();

                Product::whereIn('id', $childrenRemoved)
                    ->update(['parent_id' => null]);

                $detached = Product::whereIn('id', $childrenRemoved)->get();
                foreach ($detached as $child) {
                    event(new \App\Events\ProductUpdated($child));
                }
            }

            $parent->refresh();

            $this->service->logChange(
                (int) $parent->id,
                'update_parent',
                [
                    'before' => $before,
                    'after' => [
                        'title' => $parent->title,
                        'description' => $parent->description,
                    ],
                    'children_added' => $childrenAdded,
                    'children_removed' => $childrenRemoved,
                ]
            );

            event(new \App\Events\ProductUpdated($parent));

            return ['code' => 200, 'message' => 'OK'];
        });
    }
}
