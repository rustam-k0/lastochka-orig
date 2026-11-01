<?php

namespace App\GraphQL\Validators\Delivery;

use App\Models\DeliveryOrder;
use App\Models\Status;
use App\Rules\CourierHasOrderRule;
use Nuwave\Lighthouse\Validation\Validator;

final class DeliveryOrderChangePriorityValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      'id' => ['bail','required', new CourierHasOrderRule(),function ($attribute, $value, $fail) {
        $delivery_order = DeliveryOrder::auth()->find($value);
        if ($delivery_order->delivery->started_at != NULL) {
          $fail("Нельзя поменять позицию. Доставка уже была начата.");
          return;
        }
        if ($delivery_order->status_id !== Status::DELIVERY_WAIT_TO_START) {
          $fail("Нельзя поменять позицию. Доставка уже была начата");
        }
      }],
      'priority' => ['required', 'gt:0']
    ];
  }
}
