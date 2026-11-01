<?php

namespace App\Policies;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ShopPolicy
{
  use HandlesAuthorization;

  public function before(User $user)
  {
    if ($user->isGlobalScope()) {
      return true;
    }
  }

  public function onlyShop(User $user): bool
  {
    return $user->isShopScope();
  }

  public function currentShop(User $user, Shop $shop): bool
  {
    if (!$this->onlyShop($user)) {
      return false;
    }

    return $user->shops()->whereShopId($shop->id)->exists();
  }
}
