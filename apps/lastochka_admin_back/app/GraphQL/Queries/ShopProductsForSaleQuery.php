<?php

namespace App\GraphQL\Queries;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ShopProductsForSaleQuery
{
    /**
     * Builder для получения товаров магазина для добавления в акцию
     */
    public function builder($root, array $args): Builder
    {
        $user = Auth::user();
        $shopId = $args['shop_id'];

        // В сетевом каталоге карточка товара глобальна (принадлежит якорю),
        // акция может создаваться на любой набор магазинов — пикер отдаёт весь
        // каталог сети независимо от переданного shop_id (MID-237).
        if (app(\App\NetworkCatalog\Support\NetworkCatalogMode::class)->isEnabled()) {
            $query = Product::query();

            if (isset($args['exclude_sale_id'])) {
                $query->whereDoesntHave('merchantSales', function (Builder $q) use ($args) {
                    $q->where('merchant_sales.id', $args['exclude_sale_id']);
                });
            }

            if (isset($args['search']) && $args['search'] !== '') {
                $query->where('title', 'like', '%' . $args['search'] . '%');
            }

            return $query;
        }

        // Селлер может видеть только товары своих магазинов
        if ($user && !$user->isGlobalScope()) {
            $userShopIds = $user->shops()->pluck('shop_id')->toArray();
            if (!in_array($shopId, $userShopIds)) {
                // Возвращаем пустой результат если магазин не принадлежит пользователю
                return Product::query()->whereRaw('1 = 0');
            }
        }

        $query = Product::query()
            ->where('shop_id', $shopId);

        if (isset($args['exclude_sale_id'])) {
            $query->whereDoesntHave('merchantSales', function (Builder $q) use ($args) {
                $q->where('merchant_sales.id', $args['exclude_sale_id']);
            });
        }

        if (isset($args['search']) && $args['search'] !== '') {
            $query->where('title', 'like', '%' . $args['search'] . '%');
        }

        return $query;
    }
}
