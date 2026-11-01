<?php

namespace App\GraphQL\Validators\Order;

use App\Models\Order;
use App\Models\Status;
use App\Rules\IsCourier;
use App\Rules\UserInShop;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class OrderCourierUpdateValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      'id' => ['required', Rule::exists((new Order())->getTable())
        ->where('status_id', Status::ORDER_IN_DELIVERY)
        ->whereNull('deleted_at')
      ],
      'courier_id' => ['required', new IsCourier(), new UserInShop($this->arg('id'))],
    ];
  }
}
