<?php

namespace App\GraphQL\Mutations\ShopZone;

use App\Models\Shop;
use App\Models\ShopZone;

final class ShopZoneSetActive
{
  /**
   * @param null $_
   * @param array{id:int,is_active:bool} $args
   */
  public function __invoke($_, array $args)
  {
    $zone = ShopZone::find($args['id']);

    if ($zone) {
      $zone->is_active = (bool) $args['is_active'];
      $zone->save();

      // Активность зоны меняет условия доставки: touch публикует shop.updated,
      // storefront пересчитает доступные магазины и сбросит кеш.
      Shop::find($zone->shop_id)?->touch();
    }

    return [
      'code' => 200,
      'message' => 'OK'
    ];
  }
}
