<?php

namespace App\GraphQL\Queries;

use App\Models\DeliveryOrder;
use App\Models\Order;
use App\Models\Status;
use App\Models\User;
use App\Support\JointDelivery;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Резолвер поля Order.couriers — курьеры, назначенные на доставку заказа.
 *
 * Список, а не одиночное значение: тяжёлый заказ доставляют несколько курьеров
 * (модуль heavy_orders). Назначения живут на корневом заказе, поэтому для
 * подзаказа отдаём курьеров родителя — зеркало couriers_count, который
 * JointDelivery::recalcCouriersCount тоже пишет на корень.
 *
 * Отменённые назначения (курьер отказался) исключены — состав должен совпадать
 * со счётчиком couriers_count, иначе клиент увидит «1 курьер» и пустой список.
 */
final class OrderCouriersResolver
{
  /**
   * @return Collection<int, Model>
   */
  public function __invoke(Order $order): Collection
  {
    $root = JointDelivery::rootOrder($order);

    // Тот же join, что в JointDelivery::recalcCouriersCount: рейс мог быть
    // завершён (GlobalScope NotFinished на Delivery скрыл бы курьера
    // доставленного заказа), поэтому идём по таблице, а не по relation.
    $courierIds = DeliveryOrder::whereIn('delivery_orders.order_id', JointDelivery::selfAndChildOrderIds($root))
      ->where('delivery_orders.status_id', '!=', Status::DELIVERY_CANCELED)
      ->join('deliveries', 'deliveries.id', '=', 'delivery_orders.delivery_id')
      ->whereNull('deliveries.deleted_at')
      ->distinct()
      ->pluck('deliveries.user_id');

    return User::whereIn('id', $courierIds)->get();
  }
}
