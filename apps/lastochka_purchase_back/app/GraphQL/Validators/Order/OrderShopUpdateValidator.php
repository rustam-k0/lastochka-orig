<?php

namespace App\GraphQL\Validators\Order;

use App\Models\Order;
use App\Models\Shop;
use App\Models\Status;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class OrderShopUpdateValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    $statuses = [
      Status::ORDER_CREATED,
      Status::ORDER_PREPARING,
    ];

    return [
      'order_id' => ['required', Rule::exists((new Order())->getTable(), 'id')->whereIn('status_id', $statuses)],
      'shop_id' => ['required', Rule::exists((new Shop())->getTable(), 'id')]
    ];
  }
}
