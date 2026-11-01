<?php

namespace App\GraphQL\Queries\StopList;

use App\Models\Product;
use App\Models\Status;
use App\Support\StopListType;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builder для paginate-квери shop_stop_list_products — стоп-листы как
 * представление полей карточки товара:
 *
 * «hidden»      → товары магазина со статусом «не активный» (вкл. NULL);
 * «pickup_only» → товары магазина с pickup_only = true.
 *
 * Вариации configurable-товаров (parent_id NOT NULL) исключаются — они не
 * самостоятельные позиции. Сортировка — по дате попадания в стоп-лист
 * (hidden_at / pickup_only_at), свежие первыми; NULL-даты в конце (MySQL DESC).
 */
class ShopStopListProducts
{
    public function builder($root, array $args): Builder
    {
        $type = (string) $args['type'];
        StopListType::assertValid($type);

        $query = Product::query()
            ->where('shop_id', $args['shop_id'])
            ->whereNull('parent_id');

        if ($type === StopListType::HIDDEN) {
            $query->where(function (Builder $q) {
                $q->where('status_id', '!=', Status::CODE_ACTIVE)
                    ->orWhereNull('status_id');
            });
        } else {
            $query->where('pickup_only', true);
        }

        return $query->orderByDesc(StopListType::dateColumn($type));
    }
}
