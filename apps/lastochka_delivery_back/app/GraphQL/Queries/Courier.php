<?php

namespace App\GraphQL\Queries;

use App\Models\Scopes\NotFinished;
use App\Models\User;

final class Courier
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $order_id = $args['order_id'];

    return User::with(['workingHours'])
    ->whereHas('delivery', function ($query) use ($order_id) {
      $query->withoutGlobalScopes([new NotFinished()])
        ->whereRelation('deliveryOrders', 'order_id', $order_id);
    })->first();
  }
}
