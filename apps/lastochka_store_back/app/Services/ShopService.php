<?php

namespace App\Services;

use App\Models\Client;

class ShopService
{

  protected $shop_id;

  public function __construct(?Client $client)
  {
    if ($client) {
      $this->shop_id = $client->deliveryInfo?->shop_id;
    }
  }

  protected static function getShopId()
  {
    return app(self::class)->shop_id;
  }

  public static function bindShopQuery($query)
  {
    $shop_id = self::getShopId();

    if (!$shop_id) {
      return $query->whereRelation('shop', 'default', 1);
    }

    return $query->where('shop_id', $shop_id);
  }

}
