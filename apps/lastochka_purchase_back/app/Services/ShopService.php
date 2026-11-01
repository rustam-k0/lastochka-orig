<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Shop;

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

  /**
   * ID текущего магазина клиента для кода, которому нужен не запрос, а число
   * (сетевой каталог: цена/остаток считаются в контексте магазина).
   *
   * Повторяет логику bindShopQuery: нет магазина у клиента — магазин
   * по умолчанию; нет и его — null, решение остаётся за вызывающим.
   */
  public static function getCurrentShopId(): ?int
  {
    $shopId = self::getShopId();

    if ($shopId) {
      return (int) $shopId;
    }

    $defaultShopId = Shop::where('default', 1)->value('id');

    return $defaultShopId !== null ? (int) $defaultShopId : null;
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
