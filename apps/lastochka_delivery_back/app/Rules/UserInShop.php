<?php

namespace App\Rules;

use App\Models\Order;
use App\Models\User;
use App\Models\UserShop;
use Illuminate\Contracts\Validation\Rule;

class UserInShop implements Rule
{
  private $order_id;

  /**
   * Create a new rule instance.
   *
   * @return void
   */
  public function __construct($order_id)
  {
    $this->order_id = $order_id ?? 0;
  }

  /**
   * Determine if the validation rule passes.
   *
   * @param string $attribute
   * @param mixed $value
   * @return bool
   */
  public function passes($attribute, $value)
  {
    $order = Order::find($this->order_id);

    if (!$order) {
      return true;
    }

    return UserShop::
        whereUserId($value)
      ->whereShopId($order->shop_id)
      ->exists();

  }

  /**
   * Get the validation error message.
   *
   * @return string
   */
  public function message()
  {
    return 'Пользователь не работает в данном магазине';
  }
}
