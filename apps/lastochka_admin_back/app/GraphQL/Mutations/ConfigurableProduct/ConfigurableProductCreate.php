<?php

namespace App\GraphQL\Mutations\ConfigurableProduct;

use App\Models\Product;
use App\Models\ProductAttributeChangesLog;
use App\Models\Status;
use App\NetworkCatalog\Support\NetworkCatalogMode;
use App\Services\ConfigurableProductService;
use App\Services\ModuleConfigService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConfigurableProductCreate
{
    public function __construct(private ConfigurableProductService $service)
    {
    }

    public function __invoke($_, array $args): array
    {
        if (!ModuleConfigService::isConfigurableProductsEnabled()) {
            return ['code' => 403, 'message' => 'Фича «Вариативные товары» отключена'];
        }

        if (empty($args['children_ids'])) {
            return ['code' => 422, 'message' => 'Выберите хотя бы один товар для объединения'];
        }

        $shopId = $this->resolveShopId($args);

        $childrenIds = $args['children_ids'];

        return DB::transaction(function () use ($args, $shopId, $childrenIds) {
            $children = Product::whereIn('id', $childrenIds)
                ->where('shop_id', $shopId)
                ->lockForUpdate()
                ->get();

            if ($children->count() !== count($childrenIds)) {
                return ['code' => 422, 'message' => 'Некоторые товары не найдены или принадлежат другому магазину'];
            }

            foreach ($children as $child) {
                if ($child->is_configurable) {
                    return ['code' => 422, 'message' => "Товар \"{$child->title}\" является вариативным и не может быть вариантом"];
                }
                if ($child->parent_id) {
                    return ['code' => 422, 'message' => "Товар \"{$child->title}\" уже является вариантом другого товара"];
                }
            }

            $parent = Product::create([
                'title' => $args['title'],
                'description' => $args['description'] ?? null,
                'shop_id' => $shopId,
                'is_configurable' => true,
                'status_id' => Status::CODE_ACTIVE,
            ]);

            // Observer проставит articul = CONF-{id} после создания.
            $parent->refresh();

            $firstChild = $children->first();
            $categoryIds = $firstChild->categories()->pluck('categories.id')->toArray();
            $syncCategoryIds = isset($args['category_id']) ? [$args['category_id']] : $categoryIds;
            if (!empty($syncCategoryIds)) {
                $parent->categories()->sync($syncCategoryIds);
            }

            if (isset($args['img'])) {
                $parent->saveImg($args['img']);
            }
            if (isset($args['images'])) {
                foreach ($args['images'] as $img) {
                    if ($img) {
                        $parent->saveImg($img, \App\Models\Status::CODE_SLIDER_IMG);
                    }
                }
            }

            Product::whereIn('id', $children->pluck('id'))
                ->update(['parent_id' => $parent->id]);

            $parent->refresh();
            $parent->load('categories', 'children');

            $this->service->logChange(
                (int) $parent->id,
                'create_parent',
                [
                    'title' => $parent->title,
                    'shop_id' => $parent->shop_id,
                    'children_attached' => $children->pluck('id')->all(),
                    'configurable_attribute_ids' => $args['configurable_attribute_ids'] ?? [],
                ]
            );

            event(new \App\Events\ProductUpdated($parent));

            foreach ($children as $child) {
                $child->refresh();
                event(new \App\Events\ProductUpdated($child));
            }

            return ['code' => 200, 'message' => 'OK'];
        });
    }

    /**
     * Магазин вариативного товара.
     *
     * В сетевом каталоге карточка одна на всю сеть и живёт в якорном магазине,
     * поэтому глобальный админ создаёт вариативный без выбора магазина —
     * shop_id в аргументах отсутствует и подставляется якорь (тот же паттерн,
     * что в Product\ProductUpdate::resolveNetworkCatalogShop).
     *
     * Вне сетевого режима shop_id для глобального админа обязателен: раньше это
     * гарантировала схема (shop_id: Int!), теперь — явная валидация здесь.
     */
    private function resolveShopId(array $args): int
    {
        if (!\Auth::user()->isGlobalScope()) {
            return (int) \Auth::user()->shops()->first()->shop_id;
        }

        if (!empty($args['shop_id'])) {
            return (int) $args['shop_id'];
        }

        if (app(NetworkCatalogMode::class)->isEnabled()) {
            return app(NetworkCatalogMode::class)->anchorShopId();
        }

        throw ValidationException::withMessages([
            'shop_id' => ['Поле магазин обязательно.'],
        ]);
    }
}
