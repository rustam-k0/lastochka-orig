<?php

namespace App\GraphQL\Mutations\Order;

use App\Models\Order;
use App\Models\OrderHistory;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;

final class OrderPrepare
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $order = Order::with('orderItems')->find($args['id']);

    // Самообслуживание: сборщик берёт заказ в своём приложении — он же исполнитель.
    self::orderPrepare($order, \Auth::id(), \Auth::id());

    return [
      'code' => 200,
      'message' => 'Сборка заказа началась'
    ];
  }

  /**
   * @param int|null $adminId Кто выполнил назначение (админ через "далее"/сборщик через самообслуживание).
   *                          Null — вызов пришёл по системному токену без привязанного пользователя.
   * @param int|null $oldCollectorId Прежний сборщик (для истории), если это переназначение.
   * @return void
   * @throws \Throwable
   */
  public static function orderPrepare(Order $order, int $collector_id, ?int $adminId = null, ?int $oldCollectorId = null): void
  {
    // Статус заказа меняем на сборку и крепим сборщика за ним
    \DB::transaction(function () use ($order, $collector_id, $adminId, $oldCollectorId) {

      // Re-read с lockForUpdate чтобы синхронизироваться с processCancelledEvent
      // в admin_back (общая БД). Без лока валидатор мог пропустить заказ как
      // ORDER_CREATED ровно в окно между его SELECT и нашим UPDATE — за это
      // окно cancel-event от storefront мог уже переставить status_id в CANCELLED.
      // См. LASTOCHKI-JAMI-9665.
      $fresh = Order::whereId($order->id)->lockForUpdate()->first();

      if ($fresh->status_id !== Status::ORDER_CREATED) {
        throw new \Nuwave\Lighthouse\Exceptions\AuthorizationException(
          "Заказ нельзя взять в сборку (текущий статус: {$fresh->status_id})"
        );
      }

      // Используем Eloquent update чтобы сработал Observer и событие ушло в outbox
      $fresh->update([
        'status_id' => Status::ORDER_PREPARING
      ]);

      // Если это подзаказ — перевести родителя в PREPARING.
      // Только Eloquent-update: события подзаказов заблокированы
      // OrderNotSubOrderCondition, и order.updated(assembling) родителя —
      // единственный канал, по которому storefront узнаёт о начале сборки.
      if ($fresh->parent_order_id !== null) {
        $parent = Order::whereId($fresh->parent_order_id)->lockForUpdate()->first();
        if ($parent && $parent->status_id === Status::ORDER_CREATED) {
          $parent->update(['status_id' => Status::ORDER_PREPARING]);

          OrderHistory::log(
            $parent->id,
            OrderHistory::ACTION_STATUS_CHANGED,
            OrderHistory::ACTOR_SYSTEM,
            null,
            null,
            self::statusLabel(Status::ORDER_CREATED),
            self::statusLabel(Status::ORDER_PREPARING),
            "Переведён в сборку по взятию подзаказа {$fresh->label}"
          );
        }
      }

      $assembly = $fresh->assemblies()->create([
        'user_id' => $collector_id
      ]);

      foreach ($fresh->orderItems as $order_item) {
        $assembly->assemblyItems()->create([
          'order_item_id' => $order_item->id,
          'status_id' => Status::PRODUCT_WAIT,
          'type_id' => Type::PRODUCT_WITHOUT_PROBLEMS
        ]);
      }

      $actor = $adminId ? User::find($adminId) : null;
      $oldCollector = $oldCollectorId ? User::find($oldCollectorId) : null;
      $newCollector = User::find($collector_id);

      OrderHistory::log(
        $fresh->id,
        OrderHistory::ACTION_COLLECTOR_ASSIGNED,
        OrderHistory::ACTOR_STAFF,
        $adminId,
        $actor ? trim("{$actor->surname} {$actor->name}") : null,
        $oldCollector ? trim("{$oldCollector->surname} {$oldCollector->name}") : null,
        $newCollector ? trim("{$newCollector->surname} {$newCollector->name}") : null
      );

      // Аналитика: старт сборки (LASTOCHKI-JAMI-11659). В transaction —
      // outbox-запись атомарна с созданием assembly.
      \App\Services\AnalyticsEvents::pickingStarted($assembly);
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
