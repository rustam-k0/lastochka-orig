<?php

namespace App\GraphQL\Mutations\Order;

use App\Models\Order;
use App\Models\OrderCancelReason;
use App\Models\OrderHistory;
use App\Models\Status;

final class OrderCancel
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {

    $order_id = $args['order_id'];

    $order = Order::with('orderItems.product')->find($order_id);
    $oldStatusId = $order->status_id;
    $staff = auth()->guard('api')->user();

    $activeChildren = $order->childOrders()
      ->where('status_id', '!=', Status::ORDER_CANCELED)
      ->get();

    // Курьерскую часть дёргаем ДО транзакции. cancelDelivery ходит по HTTP в
    // delivery_back, а тот работает с той же БД: изнутри нашей транзакции он
    // упирался в наши же незакоммиченные UPDATE orders и вставал на блокировке
    // строки (innodb_lock_wait_timeout = 50 c), а Guzzle сдавался через 30 c —
    // «cURL error 28», мутация падала с 500, отмена откатывалась, а закрытые
    // сборки на стороне delivery_back оставались. Порядок вызовов прежний:
    // сначала активные подзаказы, потом родитель.
    //
    // Отменить доставку/сборку каждого активного подзаказа нужно обязательно:
    // без этого сборки подзаказов (Assembly) остаются открытыми
    // (finished_at IS NULL) и навсегда занимают лимит сборщика
    // MAX_ORDERS_COUNT — сборщик упирается в «У вас максимальное
    // количество заказов на сборке».
    foreach ($activeChildren as $child) {
      $this->cancelDelivery($child->id);
    }

    $this->cancelDelivery($order->id);

    \DB::transaction(function () use ($order) {
      // Каскадно отменить дочерние подзаказы, чтобы AssemblyFinish::syncParentStatus
      // не перевернул родителя обратно в ASSEMBLED при завершении оставшейся сборки
      $order->childOrders()
        ->where('status_id', '!=', Status::ORDER_CANCELED)
        ->update(['status_id' => Status::ORDER_CANCELED]);

      $order->update([
        'status_id' => Status::ORDER_CANCELED
      ]);
    });

    foreach ($activeChildren as $child) {
      OrderHistory::log(
        $child->id,
        OrderHistory::ACTION_CANCELED,
        OrderHistory::ACTOR_STAFF,
        $staff?->id,
        $staff ? trim("{$staff->surname} {$staff->name}") : null,
        $this->statusLabel($child->status_id),
        $this->statusLabel(Status::ORDER_CANCELED),
        $this->resolveReasonText($args)
      );
    }

    OrderHistory::log(
      $order->id,
      OrderHistory::ACTION_CANCELED,
      OrderHistory::ACTOR_STAFF,
      $staff?->id,
      $staff ? trim("{$staff->surname} {$staff->name}") : null,
      $this->statusLabel($oldStatusId),
      $this->statusLabel(Status::ORDER_CANCELED),
      $this->resolveReasonText($args)
    );

    return [
      'code' => 200,
      'message' => 'OK'
    ];
  }

  /**
   * Отмена процесса доставки
   */
  protected function cancelDelivery(int $order_id): void
  {
    $url = config('app.url') . '/backend-delivery/order/cancel';
    $token = config('services.system.token');

    $data = [
      'token' => $token,
      'order_id' => $order_id,
    ];

    // Явный таймаут вместо гизлового дефолта в 30 c: эндпоинт локальный, ждать
    // его полминуты незачем — при недоступности курьерки лучше быстро вернуть
    // ошибку админу, чтобы он повторил отмену, чем держать php-fpm воркер.
    \Http::connectTimeout(5)->timeout(15)->post($url, $data);
  }

  private function statusLabel(int $statusId): string
  {
    return match ($statusId) {
      Status::ORDER_CREATED => 'Создан',
      Status::ORDER_PREPARING => 'Заказ на сборке',
      Status::ORDER_ASSEMBLED => 'Собрано',
      Status::ORDER_IN_DELIVERY => 'В доставке',
      Status::ORDER_FINISHED => 'Доставлен',
      Status::ORDER_CANCELED => 'Отменён',
      default => "Статус {$statusId}",
    };
  }

  private function resolveReasonText(array $args): ?string
  {
    if (!empty($args['custom_reason'])) {
      return trim($args['custom_reason']);
    }

    if (!empty($args['reason_id'])) {
      return OrderCancelReason::find($args['reason_id'])?->title;
    }

    return null;
  }
}
