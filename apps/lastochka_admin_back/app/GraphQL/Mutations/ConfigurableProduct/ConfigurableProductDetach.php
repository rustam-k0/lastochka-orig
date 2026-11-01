<?php

namespace App\GraphQL\Mutations\ConfigurableProduct;

use App\Models\Product;
use App\Services\ConfigurableProductService;
use App\Services\ModuleConfigService;
use Illuminate\Support\Facades\DB;

final class ConfigurableProductDetach
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

        return DB::transaction(function () use ($parent) {
            $childrenIds = $parent->children()->pluck('id')->all();
            $children = $parent->children()->get();

            $parent->children()->update(['parent_id' => null]);

            foreach ($children as $child) {
                $child->refresh();
                event(new \App\Events\ProductUpdated($child));
            }

            $this->service->logChange(
                (int) $parent->id,
                'detach_variant',
                [
                    'children_detached' => $childrenIds,
                ]
            );

            $parent->delete();

            return ['code' => 200, 'message' => 'OK'];
        });
    }
}
