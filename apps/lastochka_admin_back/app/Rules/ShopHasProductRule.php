<?php

namespace App\Rules;

use App\Models\Product;
use Illuminate\Contracts\Validation\Rule;

class ShopHasProductRule implements Rule
{
  /**
   * Create a new rule instance.
   *
   * @return void
   */
  public function __construct()
  {
    //
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
    if (\Auth::user()->isGlobalScope()) {
      return true;
    }
    $product = Product::findOr($value);

    return \Auth::user()->shops()->first()->shop_id == $product->shop_id;
  }

  /**
   * Get the validation error message.
   *
   * @return string
   */
  public function message()
  {
    return 'У вас нет доступа к редактированию данного товара';
  }
}
