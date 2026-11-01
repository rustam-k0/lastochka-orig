<?php

namespace App\GraphQL\Mutations\Delivery;

use App\Models\Delivery;
use App\Models\OrderHistory;
use App\Models\Status;
use App\Models\User;

final class DeliveryTakeOrder
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    // Модуль courier_assignment: заказы распределяет сборщик, самостоятельное
    // взятие отключено. Guard только здесь: static takeOrder() обязан работать
    // для REST-назначения из админки и DeliveryAssignCouriers.
    if (\App\Models\Order::isCourierAssignmentModuleEnabled()) {
      throw new \GraphQL\Error\UserError('Заказы назначает сборщик');
    }

    // Самообслуживание: курьер берёт заказ в своём приложении — он же исполнитель.
    self::takeOrder(\Auth::id(), $args['order_id'], \Auth::id());

    return [
      'code' => 200,
      'message' => 'OK'
    ];
  }

  /**
   * @param int|null $adminId Кто выполнил назначение. Null — системный токен без пользователя.
   * @param int|null $oldCourierId Прежний курьер (для истории), если это переназначение.
   */
  public static function takeOrder(int $courier_id, int $order_id, ?int $adminId = null, ?int $oldCourierId = null)
  {
    $order = \App\Models\Order::findOrFail($order_id);
    if ($order->parent_order_id !== null) {
      throw new \GraphQL\Error\Error('Cannot take a sub-order for delivery');
    }

    $delivery = Delivery::whereUserId($courier_id)
      ->first() ?? Delivery::create(['user_id' => $courier_id]);

    if ($delivery->deliveryOrders()->whereOrderId($order_id)->exists()) {
      return null;
    }

    $delivery_order = $delivery->deliveryOrders()->create([
      'order_id' => $order_id,
      'status_id' => Status::DELIVERY_WAIT_TO_START,
    ]);

    $actor = $adminId ? User::find($adminId) : null;
    $oldCourier = $oldCourierId ? User::find($oldCourierId) : null;
    $newCourier = User::find($courier_id);

    OrderHistory::log(
      $order_id,
      OrderHistory::ACTION_COURIER_ASSIGNED,
      OrderHistory::ACTOR_STAFF,
      $adminId,
      $actor ? trim("{$actor->surname} {$actor->name}") : null,
      $oldCourier ? trim("{$oldCourier->surname} {$oldCourier->name}") : null,
      $newCourier ? trim("{$newCourier->surname} {$newCourier->name}") : null
    );

    \App\Support\JointDelivery::recalcCouriersCount($order);

    // Аналитика: курьер назначен (LASTOCHKI-JAMI-11659). Покрывает и
    // самообслуживание, и назначение админом (DeliveryAssignCouriers).
    \App\Services\AnalyticsEvents::courierAssigned($delivery, $order);

    return compact('delivery', 'delivery_order');

  }
}
