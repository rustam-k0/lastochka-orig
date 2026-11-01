<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\Store\MenuSectionData;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MenuSectionResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Store;
use App\Services\Catalog\StoreOfferContext;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

#[Group('Stores', weight: 2)]
final class MenuSectionController extends Controller
{
    /**
     * Максимум товаров в одной секции меню — защита от аномально большого ответа.
     */
    private const MAX_PRODUCTS_PER_SECTION = 200;

    /**
     * Секции меню магазина типа «ресторан»
     *
     * Возвращает единым списком, без пагинации, все секции меню (товарные группы
     * и категории вперемешку) с товарами каждой секции, не более 200 товаров на секцию.
     * Доступно только для активных магазинов с `type == restaurant`. Если магазин не найден,
     * неактивен или имеет другой тип, возвращается 404 (для обычных магазинов используются
     * существующие /categories и /product-groups эндпоинты).
     *
     * Сквозной `sortOrder` задаёт единый порядок вывода для групп и категорий
     * и определяется бэкендом.
     *
     * @response array{data: MenuSectionResource[]}
     */
    public function index(int $storeId): AnonymousResourceCollection
    {
        $store = Store::query()->active()->find($storeId);

        if ($store === null || $store->type !== 'restaurant') {
            abort(404);
        }

        $sections = $this->orderedSections($store);

        $data = $sections->values()->map(function (array $section, int $index) use ($store) {
            $model = $section['model'];
            $isGroup = $section['type'] === 'product_group';

            return new MenuSectionData(
                type: $section['type'],
                id: $model->id,
                title: $isGroup ? (string) $model->title : (string) $model->name,
                imagePath: $model->image_path,
                sortOrder: $index + 1,
                products: $isGroup
                    ? $this->productGroupProducts($model)
                    : $this->categoryProducts($store, $model),
            );
        });

        return MenuSectionResource::collection($data);
    }

    /**
     * Товарные группы (show_on_store_page) и корневые категории магазина,
     * объединённые в один список и отсортированные по позиции в магазине.
     *
     * @return Collection<int, array{type: string, position: int, model: ProductGroup|Category}>
     */
    private function orderedSections(Store $store): Collection
    {
        $groups = ProductGroup::query()
            ->where('store_id', $store->id)
            ->active()
            ->forStorePage()
            ->orderBy('position')
            ->get();

        // addSelect после scope — orderedForStore делает ->select('categories.*'),
        // addSelect дополняет его нужным нам полем позиции в магазине.
        // В сетевом режиме pivot не приджойнен — позиция берётся глобальная
        // (см. Category::storePositionColumn()).
        $categories = Category::query()
            ->orderedForStore($store->id)
            ->addSelect(Category::storePositionColumn().' as store_position')
            ->root()
            ->active()
            ->get();

        return $groups
            ->map(fn (ProductGroup $group) => [
                'type' => 'product_group',
                'position' => (int) $group->position,
                'model' => $group,
            ])
            ->concat($categories->map(fn (Category $category) => [
                'type' => 'category',
                'position' => (int) $category->store_position,
                'model' => $category,
            ]))
            ->sortBy('position');
    }

    /**
     * @return Collection<int, Product>
     */
    private function productGroupProducts(ProductGroup $group): Collection
    {
        return $group->products()
            ->with('images')
            // Цена/остаток секции — из офферов магазина группы (сетевой режим).
            ->with(StoreOfferContext::eagerLoad((int) $group->store_id))
            ->withCount(['supplements', 'supplements as supplements_required_count' => fn ($q) => $q->where('is_required', true)])
            ->limit(self::MAX_PRODUCTS_PER_SECTION)
            ->get();
    }

    /**
     * @return Collection<int, Product>
     */
    private function categoryProducts(Store $store, Category $category): Collection
    {
        return Product::query()
            ->purchasableInStoreExists($store->id)
            ->forCategory($category->id)
            ->ordered()
            ->with('images')
            ->with(StoreOfferContext::eagerLoad($store->id))
            ->withCount(['supplements', 'supplements as supplements_required_count' => fn ($q) => $q->where('is_required', true)])
            ->limit(self::MAX_PRODUCTS_PER_SECTION)
            ->get();
    }
}
