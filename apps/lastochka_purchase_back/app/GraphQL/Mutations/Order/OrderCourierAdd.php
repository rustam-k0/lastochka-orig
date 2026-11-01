<?php

namespace App\GraphQL\Mutations\Order;

final class OrderCourierAdd
{
  /**
   * @param null $_
   * @param array{id: int, courier_id: int} $args
   */
  public function __invoke($_, array $args)
  {
    $body = [
      'token' => config('services.system.token'),
      'order_id' => $args['id'],
      'courier_id' => $args['courier_id'],
      'admin_id' => auth()->guard('api')->user()?->id,
    ];

    $url = config('app.url') . "/backend-delivery/order/delivery";
    \Log::info('OrderCourierAdd', ['url' => $url, 'body' => $body]);

    $result = \Http::post($url, $body);

    return [
      'code' => $result->successful() ? 200 : 500,
      'message' => $result->successful() ? 'OK' : $result->json('message'),
    ];
  }
}
