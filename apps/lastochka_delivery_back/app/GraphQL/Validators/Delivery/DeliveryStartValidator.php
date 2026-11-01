<?php

namespace App\GraphQL\Validators\Delivery;

use App\Models\Delivery;
use Nuwave\Lighthouse\Validation\Validator;

final class DeliveryStartValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      'delivery' => ['required', function ($attribute, $value, $fail) {

        $delivery = Delivery::auth()->with('deliveryOrders')
          ->whereNull('started_at')
          ->first();

        if (!$delivery) {
          $fail("Не выбраны заказы для доставки");
          return;
        }

        if (!$this->checkOrders($delivery->deliveryOrders)) {
          $fail("Не корректно выбраны позиции для заказа");
        }
      }]
    ];
  }

  public function checkOrders($orders): bool
  {
    return $orders->count() == $orders->where('priority','>',0)->unique('priority')->count();
  }
}
