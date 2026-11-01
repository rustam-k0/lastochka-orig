<?php

namespace App\GraphQL\Validators\Order;

use App\Models\Assembly;
use App\Models\Order;
use App\Models\Status;
use Nuwave\Lighthouse\Validation\Validator;

final class OrderPrepareValidator extends Validator
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
          //Если заказ в магазине сборщика отсутствует, выдаем ошибку
          $exists = Order::currentShop()->whereId($value)->exists();
          if (!$exists) {
            $fail("Заказ отсутствует");
          }
        },
        // Если заказ не новый, выдаем ошибку
        function ($attribute, $value, $fail) {
          $order = Order::find($value);
          if ($order->status_id != Status::ORDER_CREATED) {
            $fail("Товар нельзя взять в сборку, т.к. это не новый заказ");
          }
        },
        // Модуль «Самовывоз без сборщиков»: pickup-заказ нельзя взять через
        // самообслуживание (обрабатывается на стороне 1С). Назначение из
        // админки идёт по REST мимо валидатора и остаётся доступным.
        function ($attribute, $value, $fail) {
          $order = Order::find($value);
          if ($order && (int) $order->type_id === \App\Models\Type::DELIVERY_PICKUP
            && Order::isPickupSkipCollectorsModuleEnabled()) {
            $fail("Заказы с типом «Самовывоз» не передаются сборщикам");
          }
        },
        // Если у сборщика уже максимум заказов выдаем ошибку
        function ($attribute, $value, $fail) {
          $available = \Auth::user()->assemblies()->count() < Assembly::MAX_ORDERS_COUNT;
          if (!$available) {
            $fail("У вас максимальное количество заказов на сборке");
          }
        }
      ]
    ];
  }
}
