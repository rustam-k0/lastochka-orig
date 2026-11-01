<?php

namespace App\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User;

class ClientPolicy
{
  use HandlesAuthorization;

  /**
   * Create a new policy instance.
   *
   * @return void
   */
  public function __construct()
  {
    //
  }

  public function authorized()
  {
    return \Auth::user()->is_authorized;
  }

  public function withDeliveryInfo(): bool
  {
    return (bool)\Auth::user()->deliveryInfo;
  }

  public function authWithDelivery(): bool
  {
    if (!$this->authorized()){
      return false;
    }

    return (bool)\Auth::user()->deliveryInfo;
  }
  public function authWithDeliveryAndCart(): bool
  {
    if (!$this->authorized()){
      return false;
    }

    if (!$this->authWithDelivery()) {
      return false;
    }

    return (bool)\Auth::user()->cart;
  }

}
