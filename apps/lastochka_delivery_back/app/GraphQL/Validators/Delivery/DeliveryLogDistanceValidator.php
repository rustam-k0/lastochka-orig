<?php

namespace App\GraphQL\Validators\Delivery;

use App\Models\Scopes\NotFinished;
use App\Models\DeliveryOrder;
use Nuwave\Lighthouse\Validation\Validator;

final class DeliveryLogDistanceValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * Принадлежность проверяем без global scope NotFinished: пробег
   * досылается и после завершения доставки (CourierHasOrderRule здесь
   * не подходит — DeliveryOrder::auth() скрывает завершённые).
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      'delivery_order_id' => ['bail', 'required', 'integer', function (string $attribute, mixed $value, $fail) {
        $ownsOrder = DeliveryOrder::withoutGlobalScopes()
          ->where('id', $value)
          ->whereHas('delivery', fn($query) => $query
            ->withoutGlobalScope(NotFinished::class)
            ->where('user_id', \Auth::id()))
          ->exists();

        if (!$ownsOrder) {
          $fail('Заказ не найден');
        }
      }],
      'distance_m' => ['required', 'integer', 'min:0', 'max:1000000'],
    ];
  }
}
