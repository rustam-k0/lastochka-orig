<?php

namespace App\Support;

use App\Models\Delivery;
use App\Models\DeliveryOrder;
use App\Models\Order;
use App\Models\OrderHistory;
use App\Models\Status;
use App\Services\FcmNotificationService;
use Illuminate\Support\Collection;

/**
 * Совместная доставка (флоу «Тяжёлый заказ»): работа с несколькими
 * назначениями delivery_orders на один заказ и денормализованным
 * счётчиком orders.couriers_count на корневом заказе.
 */
final class JointDelivery
{
  /** Корневой заказ: для подзаказов счётчик и признак живут на родителе. */
  public static function rootOrder(Order $order): Order
  {
    if ($order->parent_order_id === null) {
      return $order;
    }

    return Order::find($order->parent_order_id) ?? $order;
  }

  /** @return int[] id корневого заказа + всех его подзаказов */
  public static function selfAndChildOrderIds(Order $root): array
  {
    return array_merge(
      [$root->id],
      Order::where('parent_order_id', $root->id)->pluck('id')->all(),
    );
  }

  /**
   * Открытые (не завершённые и не отменённые) назначения по заказу и его
   * подзаказам — кандидаты на автозакрытие при совместном завершении.
   *
   * @return Collection<int, DeliveryOrder>
   */
  public static function openDeliveryOrders(Order $order): Collection
  {
    $root = self::rootOrder($order);

    return DeliveryOrder::whereIn('order_id', self::selfAndChildOrderIds($root))
      ->whereNotIn('status_id', [Status::DELIVERY_FINISHED, Status::DELIVERY_CANCELED])
      ->get();
  }

  /**
   * Пересчитать couriers_count корневого заказа: уникальные курьеры с
   * не-отменёнными назначениями (завершённые учитываются — значение
   * историческое, «сколько курьеров задействовано»).
   * Запись в orders публикует order.updated → счётчик долетает до клиента.
   */
  public static function recalcCouriersCount(Order $order): void
  {
    $root = self::rootOrder($order);

    // join на deliveries в обход GlobalScope NotFinished (рейс мог завершиться)
    $count = DeliveryOrder::whereIn('delivery_orders.order_id', self::selfAndChildOrderIds($root))
      ->where('delivery_orders.status_id', '!=', Status::DELIVERY_CANCELED)
      ->join('deliveries', 'deliveries.id', '=', 'delivery_orders.delivery_id')
      ->whereNull('deliveries.deleted_at')
      ->distinct()
      ->count('deliveries.user_id');

    $root->update(['couriers_count' => $count]);
  }

  /**
   * Совместный старт: когда один курьер тяжёлого заказа поехал, остальные
   * назначения того же заказа тоже переходят в «В пути».
   *
   * Заказ везут вместе, поэтому «в ожидании» у соисполнителя после старта
   * коллеги — рассинхрон, а не состояние (MID-268): курьер видел «В ожидании»,
   * пока сам не нажимал «Поехали». Зеркало closeOtherAssignments, который так же
   * доводит до конца назначения, оставшиеся после завершения доставки.
   *
   * Вызывается из ВСЕХ точек, где назначение становится текущим: «Поехали»
   * (DeliveryStart) и продвижение маршрута после доставки/отмены предыдущего
   * заказа (DeliveryFinish, OrderCancelByCourier, closeOtherAssignments). До
   * re-test MID-268 синк был только на «Поехали», и очередь, дошедшая до
   * тяжёлого заказа через продвижение, соисполнителя не трогала.
   *
   * НЕ гейтится модулем: >1 открытого назначения возможно только если фича
   * была включена — хвосты должны досинхронизироваться и после выключения флага.
   *
   * Не трогаем только курьера, который прямо сейчас везёт ДРУГОЙ заказ
   * («В пути»/«Приехал»): принудительный старт дал бы ему два текущих заказа и
   * сбил маршрут — joint-заказ у него стартует сам, когда очередь дойдёт.
   * Порядок по priority здесь не критерий: у соисполнителя приоритеты часто не
   * выбраны вовсе (NULL), а физически он уже едет с коллегой.
   *
   * @param  int|null  $exceptDeliveryOrderId  назначение инициатора (не трогаем)
   * @return int[] user_id курьеров, чьи назначения переведены в «В пути» (для push)
   */
  public static function startOtherAssignments(Order $order, ?int $exceptDeliveryOrderId): array
  {
    $startedCourierIds = [];

    $others = self::openDeliveryOrders($order)
      ->where('status_id', Status::DELIVERY_WAIT_TO_START)
      ->when($exceptDeliveryOrderId !== null, fn($c) => $c->where('id', '!=', $exceptDeliveryOrderId));

    foreach ($others as $other) {
      $delivery = Delivery::withoutGlobalScopes()
        ->whereNull('deleted_at')
        ->find($other->delivery_id);

      // Рейс завершён — назначение уже неактуально, трогать нечего
      if (!$delivery || $delivery->finished_at !== null) {
        continue;
      }

      $busyWithAnotherOrder = $delivery->deliveryOrders()
        ->where('id', '!=', $other->id)
        ->whereIn('status_id', [Status::DELIVERY_IN_PROGRESS, Status::DELIVERY_ARRIVED])
        ->exists();

      if ($busyWithAnotherOrder) {
        continue;
      }

      $other->update([
        'status_id' => Status::DELIVERY_IN_PROGRESS,
        'started_at' => now(),
      ]);

      if ($delivery->started_at === null) {
        $delivery->update(['started_at' => now()]);
      }

      $startedCourierIds[] = (int) $delivery->user_id;
    }

    return array_values(array_unique($startedCourierIds));
  }

  /**
   * Push соисполнителям, чьи назначения переведены в «В пути» системно.
   * Звать ПОСЛЕ коммита транзакции, в которой шёл startOtherAssignments.
   *
   * @param  int[]  $courierIds
   */
  public static function notifyStarted(array $courierIds, int $orderId): void
  {
    foreach ($courierIds as $courierId) {
      app(FcmNotificationService::class)->sendToUser(
        $courierId,
        'Доставка начата',
        'Соисполнитель начал доставку заказа. Доставка переведена в статус «В пути».',
        ['type' => 'delivery_started', 'order_id' => (string) $orderId]
      );
    }
  }

  /**
   * Совместное прибытие: курьер отметил «Приехал на место выдачи» — те же
   * отметки получают соисполнители, которые везут этот заказ («В пути»).
   * Заказ один и едет в одной машине, поэтому отдельная отметка каждого
   * курьера — рассинхрон (re-test MID-268: у соисполнителя «Приехал» не
   * появлялся, статус висел до самого завершения). Назначение, которое ещё
   * не стартовало (курьер занят другим заказом), не трогаем: «Приехал» без
   * «В пути» невозможен.
   *
   * @param  int|null  $exceptDeliveryOrderId  назначение инициатора (не трогаем)
   * @return int[] user_id курьеров, чьи назначения переведены в «Приехал» (для push)
   */
  public static function arriveOtherAssignments(Order $order, ?int $exceptDeliveryOrderId): array
  {
    $arrivedCourierIds = [];

    $others = self::openDeliveryOrders($order)
      ->where('status_id', Status::DELIVERY_IN_PROGRESS)
      ->when($exceptDeliveryOrderId !== null, fn($c) => $c->where('id', '!=', $exceptDeliveryOrderId));

    foreach ($others as $other) {
      $delivery = Delivery::withoutGlobalScopes()
        ->whereNull('deleted_at')
        ->find($other->delivery_id);

      if (!$delivery || $delivery->finished_at !== null) {
        continue;
      }

      $other->update([
        'status_id' => Status::DELIVERY_ARRIVED,
        'arrived_at' => now(),
      ]);

      $arrivedCourierIds[] = (int) $delivery->user_id;
    }

    return array_values(array_unique($arrivedCourierIds));
  }

  /**
   * Push соисполнителям, чьи назначения переведены в «Приехал» системно.
   * Звать ПОСЛЕ коммита транзакции.
   *
   * @param  int[]  $courierIds
   */
  public static function notifyArrived(array $courierIds, int $orderId): void
  {
    foreach ($courierIds as $courierId) {
      app(FcmNotificationService::class)->sendToUser(
        $courierId,
        'Прибытие на место',
        'Соисполнитель отметил прибытие на место выдачи. Доставка переведена в статус «Приехал».',
        ['type' => 'delivery_arrived', 'order_id' => (string) $orderId]
      );
    }
  }

  /**
   * Совместное завершение/отмена: закрыть все ПРОЧИЕ открытые назначения
   * заказа (и подзаказов), продвинув маршруты затронутых курьеров —
   * зеркало продвижения из DeliveryFinish/OrderCancelByCourier.
   *
   * НЕ гейтится модулем: >1 открытого назначения возможно только если фича
   * была включена — хвосты должны закрыться даже после выключения флага.
   *
   * Продвижение маршрута соисполнителя может сделать текущим его следующий
   * тяжёлый заказ — тогда синхронизируем старт и ЕГО соисполнителям
   * (startOtherAssignments); их user_id складываются в $startedCourierIds,
   * чтобы вызывающий код отправил push после коммита.
   *
   * @param  int|null  $exceptDeliveryOrderId  назначение инициатора (не трогаем)
   * @param  bool  $asCancelled  true → DELIVERY_CANCELED (отмена заказа/полный возврат)
   * @param  int[]  $startedCourierIds  (out) курьеры, чьи назначения переведены в «В пути» продвижением
   * @return int[] user_id затронутых курьеров (для push)
   */
  public static function closeOtherAssignments(Order $order, ?int $exceptDeliveryOrderId, bool $asCancelled, array &$startedCourierIds = []): array
  {
    $affectedCourierIds = [];

    $others = self::openDeliveryOrders($order)
      ->when($exceptDeliveryOrderId !== null, fn($c) => $c->where('id', '!=', $exceptDeliveryOrderId));

    foreach ($others as $other) {
      $wasCurrent = in_array((int) $other->status_id, [Status::DELIVERY_IN_PROGRESS, Status::DELIVERY_ARRIVED], true);

      $other->update([
        'status_id' => $asCancelled ? Status::DELIVERY_CANCELED : Status::DELIVERY_FINISHED,
        'finished_at' => now(),
      ]);

      // Отменённый заказ курьеру больше не показываем: soft-delete убирает
      // назначение из списка рейса (тот же паттерн, что в OrderCancelByCourier).
      // Без этого отменённый админом заказ висел в приложении курьера до конца
      // рейса, а при незакрытом рейсе — навсегда.
      if ($asCancelled) {
        $other->delete();
      }

      $delivery = Delivery::withoutGlobalScopes()
        ->whereNull('deleted_at')
        ->find($other->delivery_id);

      if ($delivery) {
        $affectedCourierIds[] = (int) $delivery->user_id;
      }

      if (!$delivery || $delivery->finished_at !== null) {
        continue;
      }

      // Продвигаем маршрут только если закрытый заказ был ТЕКУЩИМ у курьера
      if ($wasCurrent) {
        $next = $delivery->deliveryOrders()
          ->orderBy('priority')
          ->where('priority', '>', $other->priority)
          ->whereNotIn('status_id', [Status::DELIVERY_FINISHED, Status::DELIVERY_CANCELED])
          ->first();

        if ($next) {
          $next->update(['status_id' => Status::DELIVERY_IN_PROGRESS, 'started_at' => now()]);
          $next->load('order');
          $next->order->update(['status_id' => Status::ORDER_IN_DELIVERY]);
          Order::where('parent_order_id', $next->order_id)
            ->update(['status_id' => Status::ORDER_IN_DELIVERY]);

          // Следующий заказ тоже может быть совместным — его соисполнители
          // едут вместе с продвинутым курьером
          $startedCourierIds = array_values(array_unique(array_merge(
            $startedCourierIds,
            self::startOtherAssignments($next->order, $next->id)
          )));

          continue;
        }
      }

      // Рейс без незакрытых назначений закрываем НЕЗАВИСИМО от того, был ли
      // закрытый заказ текущим. Раньше закрытие жило только в ветке $wasCurrent,
      // поэтому отмена заказа в статусе «Ожидает начала» (флоу courier_assignment:
      // сборщик назначил, курьер ещё не выехал) оставляла рейс открытым навсегда.
      // Такой рейс держал в приложении курьера отменённый заказ, занимал лимит
      // MAX_ORDERS_COUNT и, если был уже стартован, блокировал взятие новых
      // заказов («Доставка уже началась»).
      $hasPending = $delivery->deliveryOrders()
        ->whereNotIn('status_id', [Status::DELIVERY_FINISHED, Status::DELIVERY_CANCELED])
        ->exists();

      if (!$hasPending) {
        $delivery->update(['finished_at' => now()]);
      }
    }

    if ($affectedCourierIds !== []) {
      $root = self::rootOrder($order);
      OrderHistory::log(
        $root->id,
        OrderHistory::ACTION_DELIVERY_COMPLETED,
        OrderHistory::ACTOR_SYSTEM,
        null,
        'Система',
        null,
        $asCancelled ? 'Назначения курьеров закрыты (заказ отменён)' : 'Доставка завершена другим курьером'
      );
    }

    return array_values(array_unique($affectedCourierIds));
  }
}
