<?php

namespace App\GraphQL\Mutations\Order;

use App\Models\Order;
use App\Models\Status;
use App\Models\Type;

final class OrderGoToNextStep
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $order = Order::find($args['id']);

    $token = config('services.system.token');
    $adminId = auth()->guard('api')->user()?->id;
    $data = [];

    switch ($order->status_id) {
      case Status::ORDER_CREATED:
        // запрос, чтобы началась сборка
        $endpoint = 'backend-delivery/order/prepare';
        $data['collector_id'] = $args['collector_id'];
        $data['admin_id'] = $adminId;
        break;
      case Status::ORDER_PREPARING:
        // запрос на завершение сборки
        $endpoint = 'backend-delivery/order/assembly';
        break;
      case Status::ORDER_ASSEMBLED:
        // Блокировка назначения курьера для pickup
        if ($order->type_id === Type::RECEIVE_PICKUP) {
            return ['code' => 422, 'message' => 'Для самовывоза используйте кнопку "Заказ выдан"'];
        }
        // начало доставки
        $endpoint = 'backend-delivery/order/delivery';
        $data['courier_id'] = $args['courier_id'];
        $data['admin_id'] = $adminId;
        // Явное намерение админа провести доставку: delivery_back стартует
        // рейс даже при включённом courier_assignment (MID-242). Флаг шлёт
        // только эта мутация — OrderCourierAdd назначает без старта.
        $data['start_delivery'] = true;
        break;
      case Status::ORDER_IN_DELIVERY:
        $endpoint = 'backend-delivery/order/finish';
        // на завершение заказа
        break;
      default:
        throw new \Error('Ошибка смены статуса');
    }

    $body = array_merge(['token' => $token, 'order_id' => $args['id']], $data);

    $url = config('app.url')."/".$endpoint;
    \Log::info('OrderGoToNextStep', ['url' => $url, 'body' => $body]);

    $result = \Http::post($url, $body);
    \Log::info('OrderGoToNextStep', ['result' => $result]);

    $response = [
      'code' => $result->successful() ? 200 : 500,
      'message' => $result->successful() ? 'OK' : $result->json('message')
    ];

    if (!$result->successful()) {
      return  $response;
    }

    if ($order->status_id == Status::ORDER_PREPARING) {
      $url = config('app.url')."/backend-delivery/order/assembly-finish";
      $result = \Http::post($url, $body);
      $response = [
        'code' => $result->successful() ? 200 : 500,
        'message' => $result->successful() ? 'OK' : $result->json('message')
      ];
    }

    // Сохраняем старый статус перед обновлением
    $oldStatusId = $order->status_id;

    // Перезагружаем модель из БД, чтобы получить обновленный статус
    $order->refresh();

    event('eloquent.updated: ' . Order::class, $order);

    return $response;
  }
}
