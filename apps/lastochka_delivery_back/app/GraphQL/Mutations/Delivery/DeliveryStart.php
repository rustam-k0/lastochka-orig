<?php

namespace App\GraphQL\Mutations\Delivery;

use App\Models\Delivery;
use App\Models\Order;
use App\Models\Status;
use App\Support\JointDelivery;

final class DeliveryStart
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $delivery = Delivery::auth()->first();

    $delivery_order = $delivery
      ->deliveryOrders()
      ->with('order')
      ->where('status_id', Status::DELIVERY_WAIT_TO_START)
      ->orderBy('priority')
      ->first();

    self::deliveryStart($delivery, $delivery_order);


    return [
      'code' => 200,
      'message' => 'Доставка начата'
    ];
  }

  /**
   * @param $delivery
   * @param $delivery_order
   * @return void
   * @throws \Throwable
   */
  public static function deliveryStart($delivery, $delivery_order): void
  {
    $startedCourierIds = \DB::transaction(function () use ($delivery, $delivery_order) {
      $delivery->update([
        'started_at' => now(),
      ]);

      $delivery_order->update([
        'status_id' => Status::DELIVERY_IN_PROGRESS,
        'started_at' => now(),
      ]);

      $delivery_order->order->update([
        'status_id' => Status::ORDER_IN_DELIVERY
      ]);

      Order::where('parent_order_id', $delivery_order->order_id)
        ->update(['status_id' => Status::ORDER_IN_DELIVERY]);

      // Аналитика: курьер поехал (LASTOCHKI-JAMI-11659)
      \App\Services\AnalyticsEvents::deliveryStarted($delivery, $delivery_order->order);

      // Совместная доставка: соисполнители едут вместе с инициатором, их
      // назначения тоже переходят в «В пути» (MID-268)
      return JointDelivery::startOtherAssignments($delivery_order->order, $delivery_order->id);
    });

    JointDelivery::notifyStarted($startedCourierIds, (int) $delivery_order->order_id);
  }
}
