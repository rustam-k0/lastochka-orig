# Рефанд при отмене заказа

> Дата: 2026-06-17 · Тикет: tg-14175 · Модуль: aladdin_storefront_back

## Проблема

При отмене заказа, оплаченного картой, storefront возвращает деньги только если они
ещё **не списаны** (снимает холд через `cancelHold`). Если деньги уже списаны
(`captured_amount > 0`) — не возвращается ничего. YooKassa доступен только из
storefront, поэтому рефанд обязан инициировать именно он.

Дыра присутствует в двух симметричных точках:

1. **`CancelOrderAction::cancelStandard`** (строки 99-102) — клиент отменяет заказ
   через приложение (`POST /api/v1/orders/{order}/cancel`).
2. **`OrderEventTransformer::beforeUpdate`** (строки 433-452) — заказ отменяют
   снаружи (оператор / курьерка / CRM), событие прилетает в storefront по RabbitMQ.
   Главный кейс: отмена уже **собранного** заказа (`Assembled`+), где деньги при
   завершении сборки уже списаны (capture в `OrderEventTransformer:371`).

Оба блока проверяют `!$order->captured_amount` и потому молча пропускают возврат
списанных денег.

## Когда captured_amount > 0 при отмене

- В обычном флоу capture происходит при переходе в `Assembled`
  (`OrderEventTransformer:371`). `canCancel()` разрешает клиентскую отмену только в
  `Pending`/`Assembling`, поэтому клиент почти всегда отменяет до списания.
  Исключение — YooKassa-автокапчур холда по таймауту `waiting_for_capture` (webhook
  `handlePaymentSucceeded`) → заказ может стать captured ещё в `Pending`/`Assembling`.
- Внешняя отмена (RabbitMQ) **не ограничена** `canCancel()` — оператор отменяет заказ
  в любом статусе, в т.ч. `Assembled`/`InDelivery`, где `captured_amount > 0`.
  Это основной сценарий, ради которого делается фича.

## Решение

### 1. Общий метод `PaymentService::refundOrReleaseHold`

Инкапсулирует «деньги списаны → вернуть остаток, иначе снять холд», симметрично
существующему `AutoCancelPickupOrderAction:81-82`.

```php
public function refundOrReleaseHold(Order $order): PaymentResult
{
    if ($order->payment_type !== PaymentType::Card) {
        return new PaymentResult(success: true);
    }

    $refundable = round(
        (float) ($order->captured_amount ?? 0) - (float) ($order->refunded_amount ?? 0),
        2,
    );

    if ($refundable > 0.01) {
        return $this->refundPayment($order, $refundable);
    }

    if ((float) ($order->held_amount ?? 0) > 0) {
        return $this->cancelHold($order);
    }

    return new PaymentResult(success: true);
}
```

Инвариант: при наличии `captured` **не** вызываем `cancelHold` — холд уже закрыт
capture'ом на стороне YooKassa, вызов бы зафейлился. Порог `0.01` — защита от
float-погрешности (как в `FinalizeClientOrderAction`).

### 2. Точка 1 — клиентская отмена

`CancelOrderAction::cancelStandard` блок 99-102 заменяется на вызов
`refundOrReleaseHold($order)`, обёрнутый в `try/catch` + `Log::error`. Рефанд-сбой
(недоступность YooKassa) **не откатывает** отмену: товары/бонусы/статус уже применены,
рефанд до-инициируется ретраем/вручную. Best-effort, как в `OrderEventTransformer`.

**Инвариант I-3**: тело `cancelStandard` защищено комментарием «не менять без
отдельного PR с тестами в форках». Правка делается на ветке `tg-14175` с тестами и
помечается для sync-core broadcast на Aladdin/Lastochka/Electrohub/Foodlink/Cron
(generic платёжный код, идентичный между форками).

### 3. Точка 2 — внешняя отмена (RabbitMQ)

`OrderEventTransformer::beforeUpdate` блок 433-452 заменяется на тот же
`refundOrReleaseHold($order)` (`try/catch` уже есть) **плюс marketplace-guard**: для
marketplace sub-order (`ModuleConfigService::isMarketplaceMode() && client_order_id !== null`)
пропускаем — там consolidated refund делает `FinalizeClientOrderAction` (как уже
сделано для бонусов на стр. 526).

Идемпотентность:
- terminal-guard (`OrderEventTransformer:333-344`) обнуляет `newStatus` при повторном
  `cancelled`-событии на уже отменённый заказ → refund-блок не выполняется второй раз;
- `refundPayment` сам считает `maxRefundable = captured - alreadyRefunded`; при уже
  возвращённой сумме `refundable = 0` → метод не вызывает рефанд.

### 4. Что НЕ трогаем

- `OrderStatus::canCancel()` — без изменений (клиент отменяет только `Pending`/`Assembling`).
- Marketplace `cancelSubOrder` + `FinalizeClientOrderAction` — уже возвращают разницу
  `hold − captured` корректно.
- `refundPayment` / `cancelHold` — без изменений.

### 5. AutoCancelPickupOrderAction

Рефактор inline-блока (строки 81-82) на `refundOrReleaseHold` для DRY. Поведение не
меняется: pickup всегда captured при `Assembled`, ветка `refundable > 0` срабатывает
как и раньше.

## Тесты (PHPUnit, FakePaymentGateway, запуск ТОЛЬКО локально, PHP 8.4)

- Unit `PaymentService::refundOrReleaseHold`:
  - captured без refunded → `refund` на полную captured;
  - captured с частичным refunded → `refund` на остаток;
  - held-only (captured = 0) → `cancelHold`;
  - captured = 0, held = 0 → no-op success;
  - payment_type != Card → no-op success.
- Feature `CancelOrderAction`:
  - captured-заказ отменён → зафиксирована refund-транзакция на остаток;
  - held-only → холд снят, refund не вызван.
- Feature `OrderEventTransformer` (входящее `order_cancelled`):
  - captured-заказ → refund;
  - marketplace sub-order → refund/cancelHold пропущены.

## Файлы

- `app/Services/Payment/PaymentService.php` — новый метод.
- `app/Actions/Order/CancelOrderAction.php` — точка 1 (I-3).
- `app/RabbitMQ/Transformers/OrderEventTransformer.php` — точка 2 + marketplace-guard.
- `app/Actions/Order/AutoCancelPickupOrderAction.php` — рефактор DRY.
- `tests/Unit/Services/Payment/` + `tests/Feature/...` — тесты.

## Раскатка

После merge — sync-core broadcast метода и обеих точек на форки; верификация
присутствия коммитов через `git log --grep` на каждом форке (Aladdin/Lastochka/
Electrohub/Foodlink/Cron).
