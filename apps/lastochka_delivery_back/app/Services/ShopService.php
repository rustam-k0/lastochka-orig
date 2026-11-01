<?php

namespace App\Services;

use App\Models\User;

class ShopService
{

  protected ?array $shop_ids = null;

  public function __construct(?User $user)
  {
    if ($user) {
      $this->shop_ids = $user->shops()->pluck('shops.id')->toArray();
    }
  }

  protected static function getShopIds(): ?array
  {
    return app(self::class)->shop_ids;
  }

  public static function bindShopQuery($query)
  {
    $shop_ids = self::getShopIds();

    if (empty($shop_ids)) {
      return $query->whereRelation('shop', 'default', 1);
    }

    return $query->whereIn('shop_id', $shop_ids);
  }

}
