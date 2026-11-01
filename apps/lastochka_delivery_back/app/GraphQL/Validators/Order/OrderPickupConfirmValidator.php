<?php

namespace App\GraphQL\Validators\Order;

use App\GraphQL\Mutations\Order\OrderPickupConfirm;
use App\Models\Assembly;
use App\Models\Order;
use App\Models\Scopes\NotFinished;
use App\Models\Status;
use App\Models\Type;
use Closure;
use Nuwave\Lighthouse\Validation\Validator;

/**
 * Проверки перед выдачей самовывоза сборщиком.
 *
 * Сборщик видит во вкладке «Собрано» только свои сборки (scope auth в
 * collector_orders_paginate), поэтому и выдать может только тот заказ,
 * который собирал сам.
 */
final class OrderPickupConfirmValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      'id' => ['bail', 'required',
        function ($attribute, $value, $fail) {
          // Заказ должен быть в магазине сборщика
          if (!Order::currentShop()->whereId($value)->exists()) {
            $fail("Заказ отсутствует");
          }
        },
        function ($attribute, $value, $fail) {
          $order = Order::find($value);
          $this->ensureOrderIssuable($order, $fail);
        }
      ]
    ];
  }

  /**
   * Заказ можно выдать: это самовывоз, он собран целиком и собирал его
   * текущий сотрудник. Вынесено в отдельный метод — как в
   * DeliveryTakeOrderValidator, чтобы проверки покрывались тестами в изоляции.
   */
  protected function ensureOrderIssuable(?Order $order, Closure $fail): void
  {
    if (!$order) {
      $fail("Заказ не найден");
      return;
    }

    $root = OrderPickupConfirm::resolveRoot($order);

    if ((int) $root->type_id !== Type::DELIVERY_PICKUP) {
      $fail("Заказ не оформлен на самовывоз");
      return;
    }

    if ((int) $root->status_id === Status::ORDER_FINISHED) {
      $fail("Заказ уже выдан");
      return;
    }

    if ((int) $root->status_id === Status::ORDER_CANCELLED) {
      $fail("Заказ отменён");
      return;
    }

    if ((int) $root->status_id !== Status::ORDER_ASSEMBLED) {
      // При сплите это значит, что часть подзаказов ещё не собрана:
      // родитель переходит в ORDER_ASSEMBLED только когда закрыты все.
      $fail("Заказ ещё не собран");
      return;
    }

    if (!$this->assembledByCurrentUser($root)) {
      $fail("Заказ собирал другой сборщик");
    }
  }

  /**
   * Собирал ли заказ текущий сотрудник: сборка на самом заказе или на любом
   * из его подзаказов. Global scope NotFinished снимаем — к моменту выдачи
   * сборка уже завершена (finished_at заполнен).
   */
  protected function assembledByCurrentUser(Order $root): bool
  {
    $orderIds = Order::where('parent_order_id', $root->id)
      ->pluck('id')
      ->push($root->id)
      ->all();

    return Assembly::withoutGlobalScope(NotFinished::class)
      ->whereIn('order_id', $orderIds)
      ->where('user_id', \Auth::id())
      ->exists();
  }
}
