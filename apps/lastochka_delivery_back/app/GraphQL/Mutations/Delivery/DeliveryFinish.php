<?php

namespace App\GraphQL\Mutations\Delivery;

use App\Events\ProductStockChanged;
use App\GraphQL\Mutations\Assembly\AssemblyFinish;
use App\Models\Delivery;
use App\Models\DeliveryOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Status;
use App\Services\CourierReturnService;
use App\Support\StockUnits;

final class DeliveryFinish
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $delivery_order = DeliveryOrder::with(['order', 'delivery'])->find($args['id']);

    $delivery = $delivery_order->delivery;

    self::deliveryFinish($delivery_order, $delivery);

    return [
      'code' => 200,
      'message' => 'OK'
    ];
  }

  /**
   * Завершить доставку
   */
  public static function deliveryFinish(DeliveryOrder $delivery_order, Delivery $delivery): void
  {
    // Содержится ли следующий по очереди доставки заказ
    $next_order = $delivery->deliveryOrders()
      ->orderBy('priority')
      ->where('id', '!=', $delivery_order->id)
      ->where('priority','>',$delivery_order->priority)
      ->first();

    $closedCourierIds = [];
    $startedCourierIds = [];

    \DB::transaction(function () use ($delivery_order, $next_order, $delivery, &$closedCourierIds, &$startedCourierIds) {

      // Settlement возвратов курьера (tg-14265): вернуть остаток на склад и
      // пересчитать order.amount = delivered ДО смены статуса, чтобы исходящее
      // outbox-событие несло корректную сумму. Возвращает true, если доставлено
      // 0 (всё вернули) — тогда трактуем заказ как отменённый.
      $allReturned = self::applyCourierReturns($delivery_order->order);

      //Завершаем доставку заказа (или отменяем, если всё вернули)
      $delivery_order->update([
        'status_id' => $allReturned ? Status::DELIVERY_CANCELED : Status::DELIVERY_FINISHED,
        'finished_at' => now()
      ]);

      //Обновляем статус заказа (один Eloquent-update → одно событие в outbox
      //с уже пересчитанной суммой)
      $orderUpdate = ['status_id' => $allReturned ? Status::ORDER_CANCELLED : Status::ORDER_FINISHED];
      // finished_at фиксируем ТОЛЬКО при фактическом завершении доставки (не при
      // отмене) и только если он ещё не проставлен — заказ мог быть завершён
      // другим курьером при совместной доставке, перезаписывать нельзя.
      if (!$allReturned && $delivery_order->order->finished_at === null) {
        $orderUpdate['finished_at'] = now();
      }
      $delivery_order->order->update($orderUpdate);

      $children = Order::where('parent_order_id', $delivery_order->order_id)->get();
      Order::where('parent_order_id', $delivery_order->order_id)
        ->update(['status_id' => $allReturned ? Status::ORDER_CANCELLED : Status::ORDER_FINISHED]);
      // Подзаказам при сплите — тот же finished_at, только при завершении и
      // только тем, у кого ещё не проставлен (тот же гейт «не перезаписывать»).
      if (!$allReturned) {
        Order::where('parent_order_id', $delivery_order->order_id)
          ->whereNull('finished_at')
          ->update(['finished_at' => now()]);
      }

      foreach ($children as $child) {
        \App\Models\OrderHistory::log(
          $child->id,
          $allReturned ? \App\Models\OrderHistory::ACTION_CANCELED : \App\Models\OrderHistory::ACTION_DELIVERY_COMPLETED,
          \App\Models\OrderHistory::ACTOR_SYSTEM,
          null,
          null,
          self::statusLabel($child->status_id),
          $allReturned ? 'Отменён' : 'Доставлен',
          'Синхронизировано с завершением доставки родителя'
        );
      }

      // Совместная доставка: закрываем назначения остальных курьеров системно
      $closedCourierIds = \App\Support\JointDelivery::closeOtherAssignments(
        $delivery_order->order,
        $delivery_order->id,
        $allReturned,
        $startedCourierIds
      );

      // При полном возврате назначения отменены — счётчик курьеров пересчитываем
      // (иначе клиент видит «совместную доставку» на отменённом заказе)
      if ($allReturned) {
        \App\Support\JointDelivery::recalcCouriersCount($delivery_order->order);
      }

      $courier = \App\Models\User::find($delivery->user_id);
      $courierName = $courier ? trim("{$courier->surname} {$courier->name}") : null;
      \App\Models\OrderHistory::log(
        $delivery_order->order_id,
        $allReturned ? \App\Models\OrderHistory::ACTION_CANCELED : \App\Models\OrderHistory::ACTION_DELIVERY_COMPLETED,
        \App\Models\OrderHistory::ACTOR_STAFF,
        $delivery->user_id,
        $courierName,
        null,
        $allReturned ? 'Отменён' : 'Доставлен'
      );

      // Аналитика: заказ передан клиенту / полный возврат (LASTOCHKI-JAMI-11659)
      \App\Services\AnalyticsEvents::deliveryFinished($delivery, $delivery_order->order, $allReturned);

      //Если нет следующего по очереди заказа, завершаем доставку
      if (!$next_order) {
        $delivery->update([
          'finished_at' => now()
        ]);
        return;
      }

      $next_order->update([
        'status_id' => Status::DELIVERY_IN_PROGRESS,
        'started_at' => now(),
      ]);

      $next_order->load('order');
      $next_order->order->update([
        'status_id' => Status::ORDER_IN_DELIVERY
      ]);

      // Аналитика: следующий заказ рейса поехал (LASTOCHKI-JAMI-11659)
      \App\Services\AnalyticsEvents::deliveryStarted($delivery, $next_order->order);

      // Совместная доставка: очередь дошла до тяжёлого заказа — соисполнители
      // едут вместе, их назначения тоже «В пути» (re-test MID-268)
      $startedCourierIds = array_values(array_unique(array_merge(
        $startedCourierIds,
        \App\Support\JointDelivery::startOtherAssignments($next_order->order, $next_order->id)
      )));

      $nextChildren = Order::where('parent_order_id', $next_order->order_id)->get();
      Order::where('parent_order_id', $next_order->order_id)
        ->update(['status_id' => Status::ORDER_IN_DELIVERY]);

      foreach ($nextChildren as $child) {
        \App\Models\OrderHistory::log(
          $child->id,
          \App\Models\OrderHistory::ACTION_STATUS_CHANGED,
          \App\Models\OrderHistory::ACTOR_SYSTEM,
          null,
          null,
          self::statusLabel($child->status_id),
          self::statusLabel(Status::ORDER_IN_DELIVERY),
          'Синхронизировано с переходом родителя в доставку'
        );
      }
    });

    // Push остальным курьерам — после закрытия транзакции
    foreach ($closedCourierIds ?? [] as $courierUserId) {
      app(\App\Services\FcmNotificationService::class)->sendToUser(
        $courierUserId,
        'Заказ закрыт',
        'Заказ ' . Order::labelById((int) $delivery_order->order_id) . ' завершён другим курьером',
        ['type' => 'order_closed_by_system', 'order_id' => (string) $delivery_order->order_id]
      );
    }

    // Соисполнителям следующего совместного заказа — «Доставка начата»
    if ($startedCourierIds !== []) {
      \App\Support\JointDelivery::notifyStarted(
        $startedCourierIds,
        (int) ($next_order?->order_id ?? $delivery_order->order_id)
      );
    }
  }

  /**
   * Settlement возвратов курьера: вернуть остаток по returned_quantity,
   * пересчитать order.amount = delivered. Мутирует переданный $order in-place
   * (refresh после пересчёта), чтобы вызывающий код увидел новую сумму до
   * публикации события. Идемпотентно по факту: DeliveryFinish — терминальный
   * переход ARRIVED→FINISHED, повтор отклоняется валидатором.
   *
   * @return bool true, если доставлено 0 (всё вернули) — заказ трактуется как отменённый
   */
  private static function applyCourierReturns(Order $order): bool
  {
    $order->loadMissing('shop');

    // Читаем позиции напрямую (минуя courier-фильтр Order::orderItems,
    // который скрывает ORDER_ITEM_CANCELED у курьера).
    $items = OrderItem::with(['product', 'assemblyItem', 'supplements'])
      ->where('order_id', $order->id)
      ->get();

    $shop = $order->shop;
    $hasReturns = false;
    $returnsByProduct = []; // product_id => [returned, reason_id] для синка возврата на подзаказы

    $billable = $items->reject(
      fn (OrderItem $item) => $item->status_id === Status::ORDER_ITEM_CANCELED
    )->values();

    // Формула суммы — общая с пересчётом при самой отметке возврата
    $newItemsTotal = CourierReturnService::deliveredItemsTotal($billable);

    foreach ($billable as $order_item) {
      $product = $order_item->product;
      $returned = (int) ($order_item->returned_quantity ?? 0);

      if ($returned <= 0) {
        continue;
      }
      $hasReturns = true;
      $returnsByProduct[$order_item->product_id] = [
        'returned'  => $returned,
        'reason_id' => $order_item->return_reason_id,
      ];

      // Возврат остатка на склад (если магазин учитывает остатки и товар не в
      // per-product режиме ignore/fixed — у таких товаров остаток не списывался).
      if ($shop && !$shop->ignore_stock && $product && !$product->effectiveStockIgnored()) {
        $remain = $product->remain()
          ->withoutGlobalScope('onlyAuth')
          ->where('shop_id', $shop->id)
          ->first();
        if ($remain) {
          // В единице остатка: возвращённый целиком весовой — фактическим
          // весом, остальное — возвращёнными порциями.
          $restored = StockUnits::collectedToStock(
            $product,
            $returned >= (int) $order_item->quantity ? $order_item->actual_weight : null,
            $returned
          );
          $oldQuantity = (float) $remain->quantity;
          $newQuantity = round($oldQuantity + $restored, 3);
          $remain->update(['quantity' => $newQuantity, 'updated_at' => now()]);
          ProductStockChanged::dispatch($product, $oldQuantity, $newQuantity, $shop->code);
          \Log::info('DeliveryFinish::applyCourierReturns: stock restored', [
            'order_item_id' => $order_item->id,
            'product_id' => $product->id,
            'shop_id' => $shop->id,
            'old_quantity' => $oldQuantity,
            'new_quantity' => $newQuantity,
            'restored' => $restored,
            'returned_portions' => $returned,
          ]);
        }
      }
    }

    if (!$hasReturns) {
      return false; // возвратов не было — обычное завершение, сумму не трогаем
    }

    // Разнести возврат на позиции дочерних подзаказов (родитель→дети), чтобы
    // подзаказы тоже отражали возврат, а не висели «собран». Матчинг по
    // product_id — обратное направление AssemblyFinish::syncItemsToParent.
    $childOrderIds = Order::where('parent_order_id', $order->id)->pluck('id');
    if ($childOrderIds->isNotEmpty()) {
      $childItems = OrderItem::whereIn('order_id', $childOrderIds)->get();
      foreach ($childItems as $childItem) {
        $matchProductId = $childItem->origin_product_id ?? $childItem->product_id;
        if (!array_key_exists($matchProductId, $returnsByProduct)) {
          continue;
        }
        $r = $returnsByProduct[$matchProductId];
        OrderItem::withoutEvents(function () use ($childItem, $r) {
          $payload = ['returned_quantity' => $r['returned']];
          if (!empty($r['reason_id'])) {
            $payload['return_reason_id'] = $r['reason_id'];
          }
          $childItem->update($payload);
        });
      }
    }

    $totals = AssemblyFinish::recalcOrderTotals($order, round($newItemsTotal, 2));
    Order::whereId($order->id)->update($totals);
    $order->refresh();

    // «Всё вернули» определяем по сумме ТОВАРОВ (без доставки): amount включает
    // стоимость доставки, поэтому при полном возврате с платной доставкой
    // $totals['amount'] оставался > 0, и заказ ошибочно НЕ отменялся.
    return round($newItemsTotal, 2) <= 0.0;
  }

  private static function statusLabel(int $statusId): string
  {
    return match ($statusId) {
      Status::ORDER_CREATED => 'Создан',
      Status::ORDER_PREPARING => 'Заказ на сборке',
      Status::ORDER_ASSEMBLED => 'Собрано',
      Status::ORDER_IN_DELIVERY => 'В доставке',
      Status::ORDER_FINISHED => 'Доставлен',
      Status::ORDER_CANCELLED => 'Отменён',
      default => "Статус {$statusId}",
    };
  }
}
