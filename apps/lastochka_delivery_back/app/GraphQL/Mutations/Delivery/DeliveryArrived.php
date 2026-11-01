<?php

namespace App\GraphQL\Mutations\Delivery;

use App\Models\DeliveryOrder;
use App\Models\Status;
use App\Support\JointDelivery;

final class DeliveryArrived
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $delivery_order = DeliveryOrder::find($args['id']);

    if ($delivery_order) {
      $arrivedCourierIds = self::arrive($delivery_order);

      JointDelivery::notifyArrived($arrivedCourierIds, (int) $delivery_order->order_id);
    }

    return [
      'code' => 200,
      'message' => 'OK'
    ];
  }

  /**
   * Отметить прибытие на место выдачи. Совместная доставка: соисполнители,
   * которые везут этот заказ («В пути»), получают ту же отметку (MID-268).
   *
   * @return int[] user_id соисполнителей, переведённых в «Приехал» (для push)
   */
  public static function arrive(DeliveryOrder $delivery_order): array
  {
    return \DB::transaction(function () use ($delivery_order) {
      $delivery_order->update([
        'status_id' => Status::DELIVERY_ARRIVED,
        'arrived_at' => now()
      ]);

      // Аналитика: курьер у клиента (LASTOCHKI-JAMI-11659)
      \App\Services\AnalyticsEvents::deliveryArrived($delivery_order);

      $delivery_order->loadMissing('order');

      return JointDelivery::arriveOtherAssignments($delivery_order->order, $delivery_order->id);
    });
  }
}
