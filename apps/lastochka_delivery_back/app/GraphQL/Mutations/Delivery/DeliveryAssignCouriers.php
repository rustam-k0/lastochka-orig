<?php

namespace App\GraphQL\Mutations\Delivery;

use App\Models\Delivery;
use App\Models\DeliveryOrder;
use App\Models\Order;
use App\Services\FcmNotificationService;
use App\Support\JointDelivery;
use GraphQL\Error\UserError;

final class DeliveryAssignCouriers
{
  /**
   * @param null $_
   * @param array{order_id: int, courier_ids: array<int>} $args
   */
  public function __invoke($_, array $args)
  {
    if (!Order::isHeavyOrdersModuleEnabled() && !Order::isCourierAssignmentModuleEnabled()) {
      throw new UserError('Назначение курьеров отключено');
    }

    // Назначение живёт на корневом заказе (подзаказы зон сборки доставляются
    // вместе, takeOrder подзаказ отклоняет), а сборщик шлёт id того заказа,
    // который видит в своём списке — им может быть подзаказ.
    $order = JointDelivery::rootOrder(Order::findOrFail($args['order_id']));
    $orderId = (int) $order->id;

    foreach (array_unique($args['courier_ids']) as $courierId) {
      // Состояние рейса ДО назначения — как в OrderController::delivery
      $delivery = Delivery::whereUserId($courierId)->first();

      $data = DeliveryTakeOrder::takeOrder((int) $courierId, $orderId, \Auth::id());

      if (!$data) {
        continue; // курьер уже назначен на этот заказ — идемпотентно
      }

      // Зеркало REST-пути OrderController::delivery: рейс в пути — в хвост
      // очереди, иначе доставка стартует назначением. При модуле
      // courier_assignment автостарта нет: заказ остаётся «Назначен курьер»
      // (ASSEMBLED + WAIT_TO_START), доставку начинает курьер (DeliveryStart).
      if ($delivery && $data && $delivery->started_at != null) {
        $last_order = $delivery->deliveryOrders()->orderByDesc('priority')->first();
        DeliveryOrder::whereId($data['delivery_order']->id)->update([
          'priority' => $last_order->priority + 1,
        ]);
      } elseif (!Order::isCourierAssignmentModuleEnabled()) {
        DeliveryStart::deliveryStart($data['delivery'], $data['delivery_order']);
      }

      app(FcmNotificationService::class)->sendToUser(
        (int) $courierId,
        'Новый заказ на доставку',
        "Вам назначен заказ на доставку. Номер: {$order->label}",
        ['type' => 'order_assigned', 'order_id' => (string) $orderId]
      );
    }

    return [
      'code' => 200,
      'message' => 'OK'
    ];
  }
}
