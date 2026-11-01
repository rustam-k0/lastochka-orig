<?php

namespace App\GraphQL\Mutations\Delivery;

use App\Models\DeliveryOrder;

final class DeliveryOrderChangePriority
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    DeliveryOrder::whereId($args['id'])->update([
      'priority' => $args['priority']
    ]);

    return [
      'code' => 200,
      'message' => 'OK'
    ];
  }
}
