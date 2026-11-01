<?php

namespace App\GraphQL\Validators\Delivery;

use App\Models\Delivery;
use App\Models\DeliveryOrder;
use App\Models\Order;
use App\Models\Status;
use App\Models\Type;
use Closure;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

class DeliveryTakeOrderValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      'order_id'=>['bail','required',Rule::exists((new Order())->getTable(),'id')->whereNull('deleted_at'),

        //Проверка достигнуто ли максимальное количество заказов в доставке и проверка, началась ли доставка
        function ($attribute, $value, $fail) {
          $delivery = Delivery::auth()->with('deliveryOrders')->first();
          if (!$delivery) {
            return;
          }

          if ($delivery->started_at != null) {
            $fail("Нельзя добавить заказы в доставку. Доставка уже началась");
            return;
          }

          // Считаем только незакрытые назначения: доставленные и отменённые
          // заказы рейса лимит не занимают (иначе хвост от отменённого заказа
          // навсегда съедает слот у курьера).
          $pendingCount = $delivery->deliveryOrders()
            ->whereNotIn('status_id', [Status::DELIVERY_FINISHED, Status::DELIVERY_CANCELED])
            ->count();

          if($pendingCount >= Delivery::MAX_ORDERS_COUNT) {
            $fail("Выбрано максимальное количество заказов для доставки");
          }
        },
        // Проверка, можно ли взять заказ доставку
        function ($attribute, $value, $fail) {
          $order = Order::currentShop()->find($value);
          $this->ensureOrderTakeable($order, $fail);
        }
      ]
    ];
  }

  /**
   * Проверки заказа перед назначением курьеру.
   * Вынесено в отдельный метод, чтобы pickup-защита была покрыта unit-тестами в изоляции.
   */
  protected function ensureOrderTakeable(?Order $order, Closure $fail): void
  {
    if (!$order) {
      $fail("Заказ не найден");
      return;
    }

    if ((int) $order->type_id === Type::DELIVERY_PICKUP) {
      $fail("Заказ оформлен на самовывоз и не назначается курьеру");
      return;
    }

    $jointAllowed = $this->isJointDeliveryAllowed($order);

    if ($order->status_id != Status::ORDER_ASSEMBLED) {
      // Совместная доставка: к тяжёлому заказу можно присоединиться,
      // когда первый курьер уже в пути (флоу «Тяжёлый заказ»).
      if (!($jointAllowed && $order->status_id == Status::ORDER_IN_DELIVERY)) {
        $fail("Заказ еще не готов к доставке");
        return;
      }
    }

    if ($this->orderAlreadyTaken($order)) {
      if (!$jointAllowed) {
        $fail("Заказ уже был взят в доставку");
        return;
      }

      if ($this->courierAlreadyAssigned($order)) {
        $fail("Вы уже назначены на этот заказ");
      }
    }
  }

  /**
   * Изолированный hook для проверки занятости заказа — переопределяется в unit-тестах.
   */
  protected function orderAlreadyTaken(Order $order): bool
  {
    return DeliveryOrder::whereOrderId($order->id)->exists();
  }

  /**
   * Разрешена ли совместная доставка для заказа: модуль heavy_orders включён
   * и заказ помечен тяжёлым. Хук — переопределяется в unit-тестах.
   */
  protected function isJointDeliveryAllowed(Order $order): bool
  {
    return Order::isHeavyOrdersModuleEnabled() && (bool) $order->is_heavy;
  }

  /**
   * Назначен ли ТЕКУЩИЙ курьер на этот заказ (любой не-отменённый рейс,
   * включая завершённые). Хук — переопределяется в unit-тестах.
   */
  protected function courierAlreadyAssigned(Order $order): bool
  {
    return DeliveryOrder::whereOrderId($order->id)
      ->whereIn('delivery_id', Delivery::withoutGlobalScopes()
        ->whereNull('deleted_at')
        ->where('user_id', \Auth::id())
        ->pluck('id'))
      ->exists();
  }
}
