<?php

namespace App\GraphQL\Validators\Delivery;

use App\Models\Order;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use App\Support\JointDelivery;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

class DeliveryAssignCouriersValidator extends Validator
{
  public function rules(): array
  {
    return [
      'order_id' => ['bail', 'required',
        Rule::exists((new Order())->getTable(), 'id')->whereNull('deleted_at'),
        function ($attribute, $value, $fail) {
          if (!Order::isHeavyOrdersModuleEnabled() && !Order::isCourierAssignmentModuleEnabled()) {
            $fail('Назначение курьеров отключено');
            return;
          }

          $found = Order::currentShop()->find($value);
          if (!$found) {
            $fail('Заказ не найден');
            return;
          }

          // Курьер назначается на корневой заказ: подзаказы зон сборки едут
          // одной доставкой, а DeliveryTakeOrder::takeOrder подзаказ отклоняет.
          // Сборщик при этом работает со списком подзаказов и шлёт их id.
          $order = JointDelivery::rootOrder($found);

          if ((int) $order->type_id === Type::DELIVERY_PICKUP) {
            $fail('Заказ оформлен на самовывоз и не назначается курьеру');
            return;
          }

          // Heavy-ветки (мультикурьерность, донабор в пути) — при модуле
          // heavy_orders ИЛИ courier_assignment (Platrum 12180: совместная
          // доставка тяжёлых входит в «Назначение курьера сборщиком»).
          // При обоих выключенных признак is_heavy заморожен в БД и
          // мультиназначение не открывает (гейт выше всё равно не пустит).
          $isHeavy = Order::isHeavyDeliveryFlowEnabled() && (bool) $order->is_heavy;
          $courierIds = array_unique($this->arg('courier_ids') ?? []);

          if ($order->status_id != Status::ORDER_ASSEMBLED) {
            // Для тяжёлого заказа доп. курьера можно добавить и в пути
            if (!($isHeavy && $order->status_id == Status::ORDER_IN_DELIVERY)) {
              $fail('Заказ еще не готов к доставке');
              return;
            }
          }

          // Модуль collector_payment: курьер назначается только на оплаченный
          // онлайн-заказ (списание выполнено сборщиком)
          if (Order::isCollectorPaymentModuleEnabled()
            && (int) $order->payment_type_id === Type::PAYMENT_ONLINE
            && !$order->paid
          ) {
            $fail('Заказ не оплачен: спишите оплату перед назначением курьера');
            return;
          }

          if (!$isHeavy) {
            if (count($courierIds) > 1) {
              $fail('Несколько курьеров можно назначить только на тяжёлый заказ');
              return;
            }
            if (\App\Models\DeliveryOrder::whereOrderId($order->id)->exists()) {
              $fail('Заказ уже был взят в доставку');
            }
          }
        },
      ],
      'courier_ids' => ['required', 'array', 'min:1'],
      'courier_ids.*' => ['integer',
        function ($attribute, $value, $fail) {
          $courier = User::find($value);
          if (!$courier || !$courier->hasRole(Role::COURIER)) {
            $fail('Пользователь не является курьером');
            return;
          }

          // Гонка «курьер закрыл смену, пока открыт экран выбора»: список
          // shift_couriers строится по открытым сменам магазина, между его
          // загрузкой и назначением смена могла закрыться. Сообщение уходит
          // в мобильное приложение как есть.
          $order = Order::find($this->arg('order_id'));
          if (!$order) {
            return; // ошибку по order_id уже выставило правило выше
          }

          $shopId = (int) JointDelivery::rootOrder($order)->shop_id;

          // GlobalScope NotFinished — только открытые смены
          $onShift = Shift::whereUserId($courier->id)->whereShopId($shopId)->exists();

          if (!$onShift) {
            $name = trim("{$courier->surname} {$courier->name}") ?: "ID {$courier->id}";
            $fail("Курьер {$name} не на смене в этом магазине. Обновите список курьеров");
          }
        },
      ],
    ];
  }
}
