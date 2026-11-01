<?php

namespace App\GraphQL\Mutations\Order;

use App\GraphQL\Mutations\Delivery\DeliveryTakeOrder;
use App\Models\DeliveryOrder;
use App\Models\Order;
use App\Services\FcmNotificationService;

final class OrderCourierUpdate
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $oldCourierId = DeliveryOrder::whereOrderId($args['id'])->with('delivery')->first()?->delivery?->user_id;

    DeliveryOrder::whereOrderId($args['id'])->delete();

    $result = DeliveryTakeOrder::takeOrder($args['courier_id'], $args['id'], \Auth::id(), $oldCourierId);

    if ($result !== null) {
      app(FcmNotificationService::class)->sendToUser(
        $args['courier_id'],
        'Новый заказ на доставку',
        'Вам назначен заказ на доставку. Номер: ' . Order::labelById((int) $args['id']),
        ['type' => 'order_assigned', 'order_id' => (string) $args['id']]
      );
    }

    return [
      'code' => 200,
      'message' => 'OK'
    ];
  }
}
