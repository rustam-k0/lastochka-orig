<?php

namespace App\GraphQL\Mutations\ShopZone;

use App\Models\Shop;
use App\Models\ShopZone;

final class ShopZoneDelete
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $zone = ShopZone::find($args['id']);

    if ($zone) {
      $zone->delete();

      // Зона входит в payload магазина (delivery_price/min_order_price/free_delivery_from),
      // touch публикует shop.updated — storefront пересчитает условия доставки и сбросит кеш
      Shop::find($zone->shop_id)?->touch();
    }

    return [
      'code' => 200,
      'message' => 'OK'
    ];
  }
}
