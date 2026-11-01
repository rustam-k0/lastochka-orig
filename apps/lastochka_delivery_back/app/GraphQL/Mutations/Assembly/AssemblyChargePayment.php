<?php

namespace App\GraphQL\Mutations\Assembly;

use App\Models\Assembly;
use App\Models\Order;
use App\Models\Status;
use App\Models\Type;
use App\Services\StorefrontPaymentClient;
use App\Transformers\OrderEventTransformer;

final class AssemblyChargePayment
{
  /**
   * Служебная мутация «Списать оплату» (модуль collector_payment).
   *
   * Основной флоу списания встроен в завершение сборки (AssemblyFinish
   * вызывает chargeForAssembly перед finishOrder) — отдельная кнопка
   * сборщику не нужна. Мутация оставлена как самостоятельная точка
   * списания (ретрай без завершения, служебные сценарии).
   */
  public function __invoke($_, array $args)
  {
    $assembly = Assembly::with(['order', 'assemblyItems.orderItem.supplements'])->find($args['id']);

    if ($assembly->order->status_id === Status::ORDER_CANCELLED) {
      throw new \Nuwave\Lighthouse\Exceptions\AuthorizationException(
        'Заказ отменён клиентом, списание невозможно'
      );
    }

    return self::chargeForAssembly($assembly);
  }

  /**
   * Синхронное списание оплаты по результатам сборки (доплата разницы
   * первой, затем capture холда — на стороне storefront).
   *
   * Пересчитывает итог сборки БЕЗ списания складских остатков: списание
   * относительное и не идемпотентное, а вызов может повторяться (ретрай
   * после 402). Остаток спишется ровно один раз — при завершении сборки
   * (AssemblyFinish::finishOrder).
   *
   * Провал доплаты (402) оставляет заказ на сборке: сборщик связывается
   * с покупателем, корректирует состав или повторяет завершение.
   *
   * @return array{code: int, message: string}
   */
  public static function chargeForAssembly(Assembly $assembly): array
  {
    $order = AssemblyFinish::assemblyFinish($assembly, false);

    if ((int) $order->payment_type_id !== Type::PAYMENT_ONLINE) {
      return ['code' => 200, 'message' => 'OK'];
    }

    // Деньги живут на РОДИТЕЛЕ: splitOrderByZones (admin_back) создаёт
    // подзаказы с delivery_price = 0, bonus_spent = 0, assembly_cost = 0
    // и discount = 0 — доставка, бонусы и стоимость сборки остаются у
    // родителя, а холд в storefront сделан на его полную сумму. Списание
    // по amount подзаказа недобирало доставку: холд закрывался частично,
    // остаток ЮKassa возвращала покупателю.
    $target = self::paymentTarget($order);

    if ($target->paid) {
      return ['code' => 200, 'message' => 'OK'];
    }

    // Многозонный сплит: capture холда возможен только один раз, поэтому
    // ждём, пока все подзаказы будут собраны или отменены — сумма родителя
    // (calculateParentTotals) станет окончательной.
    if ($order->parent_order_id !== null && self::hasUnfinishedSiblings($order)) {
      return [
        'code' => 200,
        'message' => 'Оплата будет списана после сборки всех зон заказа',
      ];
    }

    return self::settleOrder($target->refresh());
  }

  /**
   * Списание итоговой суммы заказа в storefront (без пересчёта сборки).
   *
   * Точка входа для сплит-заказов, где последний подзаказ не собирается,
   * а отменяется: тогда chargeForAssembly не вызывается вовсе и списание
   * инициирует AssemblyFinish::syncParentStatus при переводе родителя
   * в ORDER_ASSEMBLED.
   *
   * Идемпотентно на двух уровнях: локально по $order->paid и на стороне
   * storefront (settleAssemblyPayment сверяет уже захваченную сумму).
   *
   * @return array{code: int, message: string}
   */
  public static function settleOrder(Order $order): array
  {
    if ((int) $order->payment_type_id !== Type::PAYMENT_ONLINE || $order->paid) {
      return ['code' => 200, 'message' => 'OK'];
    }

    // Defense-in-depth: клиент мог отменить заказ через storefront, пока каскад
    // отмены не дошёл до подзаказа. Гейт в __invoke смотрит на заказ сборки,
    // а деньги списываются с родителя — проверяем именно его.
    if ((int) $order->status_id === Status::ORDER_CANCELLED) {
      \Log::warning('Settle skipped — order is cancelled', ['order_id' => $order->id]);

      return ['code' => 200, 'message' => 'Заказ отменён, списание не выполнено'];
    }

    // storefront биндит {order} по своему первичному ключу, а он у нас лежит
    // в djami-поле orders.uuid (БД разные: delivery_back = mendev_djami).
    // У родителя uuid всегда числовой; подзаказы («500-1») сюда не приходят —
    // paymentTarget подменяет их родителем.
    $result = app(StorefrontPaymentClient::class)
      ->settleOrderPayment((int) $order->uuid, (float) $order->amount, self::orderEventPayload($order));

    if ($result['ok']) {
      self::markPaid($order);

      return ['code' => 200, 'message' => 'OK'];
    }

    if ($result['status'] === 402) {
      self::markPaymentStatus($order, 'awaiting_surcharge');

      $reason = $result['body']['message'] ?? 'Платёж отклонён';

      return [
        'code' => 402,
        'message' => "Не удалось списать оплату. {$reason} "
          . 'Свяжитесь с покупателем и повторите попытку после пополнения карты '
          . 'либо скорректируйте состав заказа.',
      ];
    }

    return [
      'code' => 500,
      'message' => 'Сервис оплаты временно недоступен. Попробуйте ещё раз',
    ];
  }

  /**
   * Payload order.updated по итогам сборки — тот же, что опубликует outbox.
   * Storefront применяет из него позиции до списания: capture сразу ставит чек
   * прихода, а событие доходит до витрины позже (прод-заказ 1064 Ласточки —
   * чек по заказанному весу). Сбой сборки payload не блокирует списание.
   */
  private static function orderEventPayload(Order $order): ?array
  {
    try {
      $order->load(config('rabbitmq-events.entities.orders.relations', []));
      $payload = app(OrderEventTransformer::class)->toEvent($order);

      return empty($payload['items']) ? null : $payload;
    } catch (\Throwable $e) {
      \Log::warning('Settle: order event payload not built', [
        'order_id' => $order->id,
        'error' => $e->getMessage(),
      ]);

      return null;
    }
  }

  /**
   * Заказ, на котором лежат деньги: для подзаказа — его родитель.
   * Родитель пересчитан AssemblyFinish::syncItemsToParent непосредственно
   * перед вызовом, поэтому amount уже учитывает результат сборки.
   */
  private static function paymentTarget(Order $order): Order
  {
    if ($order->parent_order_id === null) {
      return $order;
    }

    return Order::find($order->parent_order_id) ?? $order;
  }

  /**
   * Остались ли у подзаказа братья, которые ещё собираются.
   * Сам подзаказ на момент списания в ORDER_PREPARING (статус ASSEMBLED
   * проставит finishOrder уже после успешного списания) — исключаем его.
   */
  private static function hasUnfinishedSiblings(Order $order): bool
  {
    return Order::where('parent_order_id', $order->parent_order_id)
      ->where('id', '!=', $order->id)
      ->whereNotIn('status_id', [Status::ORDER_ASSEMBLED, Status::ORDER_CANCELLED])
      ->exists();
  }

  /**
   * Пометить заказ оплаченным. Родитель обновляется через Eloquent
   * (публикует order.updated в outbox → storefront/админка видят оплату),
   * подзаказы — mass-update: их события заблокированы OrderNotSubOrderCondition,
   * но флаг нужен локально для Order::isCompositionLockedForAssembly.
   */
  private static function markPaid(Order $order): void
  {
    $order->update([
      'paid' => true,
      'paid_at' => now(),
      'payment_status' => 'paid',
    ]);

    Order::where('parent_order_id', $order->id)->update([
      'paid' => true,
      'paid_at' => now(),
      'payment_status' => 'paid',
    ]);
  }

  /**
   * Проставить платёжный статус родителю и его подзаказам (сборщик видит
   * «Ожидает доплату» на своём подзаказе, гейт курьера — на родителе).
   */
  private static function markPaymentStatus(Order $order, string $status): void
  {
    $order->update(['payment_status' => $status]);

    Order::where('parent_order_id', $order->id)->update(['payment_status' => $status]);
  }
}
