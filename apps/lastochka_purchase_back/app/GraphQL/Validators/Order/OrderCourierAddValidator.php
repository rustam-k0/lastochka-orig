<?php

namespace App\GraphQL\Validators\Order;

use App\Models\AdminSetting;
use App\Models\Order;
use App\Models\Status;
use App\Models\Type;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class OrderCourierAddValidator extends Validator
{
  public function rules(): array
  {
    return [
      'id' => ['bail', 'required',
        Rule::exists((new Order())->getTable(), 'id')->whereNull('deleted_at'),
        function ($attribute, $value, $fail) {
          // Совместная доставка тяжёлых доступна при heavy_orders ИЛИ
          // courier_assignment (Platrum 12180)
          if (!self::isHeavyOrdersModuleEnabled() && !Order::isCourierAssignmentModuleEnabled()) {
            $fail('Назначение курьеров отключено');
            return;
          }

          $order = Order::find($value);

          if ((int) $order->type_id === Type::RECEIVE_PICKUP) {
            $fail('Для самовывоза курьеры не назначаются');
            return;
          }

          if (Order::isCollectorPaymentModuleEnabled()
            && (int) $order->payment_type_id === Type::PAYMENT_CARD
            && !$order->paid
          ) {
            $fail('Заказ не оплачен: спишите оплату перед назначением курьера');
            return;
          }

          if (!$order->is_heavy) {
            $fail('Дополнительного курьера можно добавить только на тяжёлый заказ');
            return;
          }

          if (!in_array((int) $order->status_id, [Status::ORDER_ASSEMBLED, Status::ORDER_IN_DELIVERY], true)) {
            $fail('Заказ не готов к назначению курьера');
          }
        },
      ],
      'courier_id' => ['required', 'integer'],
    ];
  }

  private static function isHeavyOrdersModuleEnabled(): bool
  {
    $raw = AdminSetting::getValue('project_modules');
    $modules = $raw ? (json_decode($raw, true) ?: []) : [];

    return (bool) ($modules['heavy_orders']['enabled'] ?? false);
  }
}
