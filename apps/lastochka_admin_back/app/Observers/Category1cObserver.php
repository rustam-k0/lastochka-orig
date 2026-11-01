<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\ProductUpdated;
use App\Models\Category1c;
use App\Models\Product;
use App\Services\Category1c\ProductShowcaseLinker;
use App\Services\ModuleConfigService;
use Illuminate\Support\Facades\DB;

/**
 * Дозаполняет products.category_1c_id у товаров, которые приехали из 1С
 * раньше своей Category1c (LASTOCHKI-JAMI bug «привязка не подхватывает товары»).
 *
 * Сценарий:
 *  1. product.created из 1c_parser приходит в admin_back с category_1c_external_id=UUID.
 *  2. Category1c с этим external_id в admin_back ещё не существует
 *     (categories синкаются отдельным потоком, могут запоздать на часы/дни).
 *  3. ProductEventTransformer::fromEvent сохраняет UUID в pending_category_1c_external_id,
 *     category_1c_id остаётся NULL.
 *  4. Позже Category1c с тем же (shop_id, external_id) создаётся → этот observer
 *     находит все pending-products и связывает их.
 *
 * Триггер `event(new ProductUpdated)` обязателен — touch не дёргает outbox observer
 * (см. memory/rabbitmq-pitfalls.md, секция «admin_back Product outbox»).
 */
class Category1cObserver
{
    public function created(Category1c $category): void
    {
        $this->resolvePending($category);
    }

    public function updated(Category1c $category): void
    {
        if ($category->wasChanged(['external_id', 'shop_id'])) {
            $this->resolvePending($category);
        }
    }

    private function resolvePending(Category1c $category): void
    {
        $externalId = $category->external_id;
        $shopId = $category->shop_id;

        if ($externalId === null || $externalId === '' || $shopId === null) {
            return;
        }

        $networkMode = ModuleConfigService::getCategory1cNetworkAnchorShopId() !== null;

        $productIds = Product::query()
            ->when(!$networkMode, fn ($query) => $query->where('shop_id', $shopId))
            ->where('pending_category_1c_external_id', $externalId)
            ->whereNull('category_1c_id')
            ->pluck('id');

        if ($productIds->isEmpty()) {
            return;
        }

        DB::table('products')
            ->whereIn('id', $productIds)
            ->update([
                'category_1c_id' => $category->id,
                'pending_category_1c_external_id' => null,
                'updated_at' => now(),
            ]);

        // Дозаполнили category_1c_id — теперь привязываем витрины, если у только что
        // приехавшей Category1c уже есть маппинг (pivot category_1c_category).
        // dispatchEvent: false — ProductUpdated дёргаем сами ниже, одним событием на товар
        // (несёт и резолв category_1c_id, и витринную связку).
        $linker = app(ProductShowcaseLinker::class);
        Product::whereIn('id', $productIds)
            ->get()
            ->each(function (Product $product) use ($linker) {
                $linker->sync($product, dispatchEvent: false);
                event(new ProductUpdated($product));
            });
    }
}
