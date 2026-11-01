<?php

namespace App\GraphQL\Mutations\Delivery;

use App\Models\DeliveryOrder;
use App\Services\AnalyticsEvents;
use GraphQL\Error\UserError;

final class DeliveryLogDistance
{
  /**
   * Приём агрегата пробега за доставку (LASTOCHKI-JAMI-11595, #11602 P2).
   *
   * Чистая телеметрия: публикует delivery.distance_logged и не меняет
   * доставку. Принадлежность заказа курьеру проверяет валидатор.
   *
   * @param null $_
   * @param array{delivery_order_id: int, distance_m: int} $args
   */
  public function __invoke($_, array $args)
  {
    $deliveryOrder = DeliveryOrder::withoutGlobalScopes()->find($args['delivery_order_id']);

    if (!$deliveryOrder) {
      throw new UserError('Заказ не найден');
    }

    AnalyticsEvents::distanceLogged($deliveryOrder, (int) $args['distance_m']);

    return [
      'code' => 200,
      'message' => 'OK'
    ];
  }
}
