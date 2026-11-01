<?php

namespace App\GraphQL\Mutations\Order;

use App\Models\Order;
use App\Models\OrderHistory;
use App\Models\Status;
use App\Models\User;

/**
 * Выдача заказа на самовывоз сборщиком из мобильного приложения.
 *
 * Зеркалит OrderPickupConfirm в purchase_back (кнопка «Заказ выдан» в
 * админке): заказ уходит в терминальный ORDER_FINISHED, фиксируются
 * picked_up_at/picked_up_by_user_id и finished_at. При сплите по зонам
 * сборки подтверждать можно любой подзаказ — завершается корневой заказ
 * и вместе с ним все его подзаказы (та же семантика, что в DeliveryFinish).
 */
final class OrderPickupConfirm
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $order = Order::findOrFail($args['id']);

    self::confirmPickup(self::resolveRoot($order), \Auth::id());

    return [
      'code' => 200,
      'message' => 'Заказ выдан'
    ];
  }

  /**
   * Корневой заказ: при сплите по зонам сборки выдаётся заказ целиком,
   * подзаказ — лишь часть сборки.
   */
  public static function resolveRoot(Order $order): Order
  {
    if ($order->parent_order_id === null) {
      return $order;
    }

    return Order::find($order->parent_order_id) ?? $order;
  }

  /**
   * @throws \Throwable
   */
  public static function confirmPickup(Order $order, ?int $staffId): void
  {
    \DB::transaction(function () use ($order, $staffId) {
      // Re-read с lockForUpdate: между проверкой валидатора и UPDATE заказ мог
      // уйти в CANCELLED (cancel-event от витрины через admin_back, общая БД)
      // или быть выдан параллельно из админки. Тот же приём, что в OrderPrepare.
      $fresh = Order::whereId($order->id)->lockForUpdate()->first();

      if ($fresh->status_id !== Status::ORDER_ASSEMBLED) {
        throw new \Nuwave\Lighthouse\Exceptions\AuthorizationException(
          "Заказ нельзя выдать (текущий статус: {$fresh->status_id})"
        );
      }

      $oldStatusId = (int) $fresh->status_id;

      // Eloquent update — чтобы Observer положил order.updated в outbox и
      // витрина увидела «Выдан». finished_at не перезаписываем: он мог быть
      // проставлен раньше (консьюмер, админка).
      $fresh->update([
        'status_id' => Status::ORDER_FINISHED,
        'picked_up_at' => now(),
        'picked_up_by_user_id' => $staffId,
        'finished_at' => $fresh->finished_at ?? now(),
      ]);

      // Подзаказы: mass update без событий — order.updated подзаказов в шину
      // не уходит (OrderNotSubOrderCondition), статус родителя уже опубликован.
      $children = Order::where('parent_order_id', $fresh->id)->get();

      if ($children->isNotEmpty()) {
        Order::where('parent_order_id', $fresh->id)
          ->update(['status_id' => Status::ORDER_FINISHED]);

        Order::where('parent_order_id', $fresh->id)
          ->whereNull('finished_at')
          ->update(['finished_at' => now()]);

        foreach ($children as $child) {
          OrderHistory::log(
            $child->id,
            OrderHistory::ACTION_DELIVERY_COMPLETED,
            OrderHistory::ACTOR_SYSTEM,
            null,
            null,
            self::statusLabel((int) $child->status_id),
            'Выдан',
            'Синхронизировано с выдачей самовывоза родителя'
          );
        }
      }

      $staff = $staffId ? User::find($staffId) : null;

      OrderHistory::log(
        $fresh->id,
        OrderHistory::ACTION_DELIVERY_COMPLETED,
        OrderHistory::ACTOR_STAFF,
        $staffId,
        $staff ? trim("{$staff->surname} {$staff->name}") : null,
        self::statusLabel($oldStatusId),
        'Выдан'
      );
    });
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
