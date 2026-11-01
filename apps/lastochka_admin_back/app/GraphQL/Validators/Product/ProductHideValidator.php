<?php

namespace App\GraphQL\Validators\Product;

use App\Models\Product;
use App\Models\Status;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class ProductHideValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      'id' => [
        'required',
        Rule::exists((new Product())->getTable())
          ->whereNull('deleted_at')
          ->where('status_id', Status::CODE_ACTIVE),
        function ($attribute, $value, $fail) {
          if (\Auth::user()->isGlobalScope()) {
            return;
          }

          $shop_id = \Auth::user()?->shops()->first()?->shop_id;
          $product = Product::firstWhere('shop_id', $shop_id);
          if (!$product) {
            $fail("Это не ваш товар");
          }
        }
      ]
    ];
  }
}
