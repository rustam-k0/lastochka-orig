<?php

namespace App\GraphQL\Mutations\Order;

use App\GraphQL\Mutations\Assembly\AssemblyCancel;
use App\Models\Order;

final class OrderCollectorUpdate
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $order = Order::with('assembly')
      ->find($args['id']);

    $oldCollectorId = $order->assembly?->user_id;

    if ($order->assembly) {
      AssemblyCancel::assemblyCancel($order->assembly->id);
    }

    OrderPrepare::orderPrepare($order, $args['collector_id'], \Auth::id(), $oldCollectorId);

    return [
      'code' => 200,
      'message' => 'OK'
    ];

  }
}
