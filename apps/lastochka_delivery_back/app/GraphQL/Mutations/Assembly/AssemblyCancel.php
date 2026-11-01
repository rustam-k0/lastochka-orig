<?php

namespace App\GraphQL\Mutations\Assembly;

use App\Models\Assembly;
use App\Models\Order;
use App\Models\Status;

final class AssemblyCancel
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $assembly_id = $args['id'];

    self::assemblyCancel($assembly_id);

    return [
      'code' => 200,
      'message' => 'OK'
    ];
  }

  public static function assemblyCancel(mixed $assembly_id): void
  {
    \DB::transaction(function () use ($assembly_id) {

      $assembly = Assembly::find($assembly_id);

      $order = $assembly->order;
      $oldStatusId = $order->status_id;

      $order->update([
        'status_id' => Status::ORDER_CREATED
      ]);

      $collector = \App\Models\User::find($assembly->user_id);
      \App\Models\OrderHistory::log(
        $order->id,
        \App\Models\OrderHistory::ACTION_ASSEMBLY_PAUSED,
        \App\Models\OrderHistory::ACTOR_STAFF,
        $assembly->user_id,
        $collector ? trim("{$collector->surname} {$collector->name}") : null,
        self::statusLabel($oldStatusId),
        self::statusLabel(Status::ORDER_CREATED),
        null
      );

      // Если это подзаказ — вернуть родителя в CREATED, когда ни один из
      // подзаказов больше не в работе. Только Eloquent-update: события
      // подзаказов заблокированы OrderNotSubOrderCondition, и откат статуса
      // до storefront доносит только order.updated(created) родителя.
      if ($order->parent_order_id !== null) {
        $parent = Order::whereId($order->parent_order_id)->lockForUpdate()->first();

        $siblingsInProgress = Order::where('parent_order_id', $order->parent_order_id)
          ->whereNotIn('status_id', [Status::ORDER_CREATED, Status::ORDER_CANCELLED])
          ->exists();

        if ($parent && $parent->status_id === Status::ORDER_PREPARING && !$siblingsInProgress) {
          $parentOldStatusId = $parent->status_id;
          $parent->update(['status_id' => Status::ORDER_CREATED]);

          \App\Models\OrderHistory::log(
            $parent->id,
            \App\Models\OrderHistory::ACTION_STATUS_CHANGED,
            \App\Models\OrderHistory::ACTOR_SYSTEM,
            null,
            null,
            self::statusLabel($parentOldStatusId),
            self::statusLabel(Status::ORDER_CREATED),
            'Синхронизировано с приостановкой сборки подзаказа'
          );
        }
      }

      $assembly->update([
        'finished_at' => now()
      ]);

      $assembly->delete();
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
