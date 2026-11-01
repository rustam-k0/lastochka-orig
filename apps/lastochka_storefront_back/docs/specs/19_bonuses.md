# ТЗ: Бонусная программа и карта лояльности

## 1. Цель

Баланс бонусов, история транзакций (начисления/списания) и карта лояльности в профиле пользователя.

## 2. Сущности

- **BonusTransaction** — транзакция (начисление/списание) с типом, источником и суммой.
- `User.bonus_balance` — текущий баланс пользователя (int, целые рубли).
- `User.bonus_reserved` *(новое)* — сумма бонусов в резерве (холды по активным заказам).
- `User.available_bonus_balance` *(новое)* — вычисляемое: `bonus_balance - bonus_reserved`. Именно оно проверяется при списании в заказе.
- **BonusHold** *(новое)* — резерв списания под конкретный заказ; в API отдельно не отдаётся.
- `User.card_number`, `card_created_at`, `card_confirmed_at` *(новое)* — карта лояльности (генерируется сервером, подтверждается 1С).

## 3. Endpoints

### 3.1 GET /api/v1/bonuses [AUTH]

История и баланс.

**Throttle:** 30 запросов/мин (общая группа с `GET /loyalty-card`).

**Query:**
- `page` — int, default 1
- `perPage` — int, default 20, max 100

**Response 200:**
```json
{
  "balance": 1500,
  "hasMore": true,
  "data": [
    {
      "id": 42,
      "type": "earn",
      "source": "online",
      "amount": 150,
      "balanceAfter": 1500,
      "description": "Начисление за заказ #1234",
      "orderId": 1234,
      "createdAt": "2026-04-15T10:30:00+00:00"
    }
  ],
  "total": 42
}
```

Поля транзакции:

| Поле | Тип | Описание |
|---|---|---|
| `id` | int | ID транзакции |
| `type` | string | `earn` \| `spend` \| `refund` (возврат списанного) \| `cancel` (отмена начисления) |
| `source` | string | `online` (заказы storefront) \| `offline` (кассовые операции из 1С) *(новое)* |
| `amount` | int | Сумма в целых рублях |
| `balanceAfter` | int | Баланс после транзакции *(новое)* |
| `description` | string | Может быть null |
| `orderId` | int | Может быть null (у offline-операций) |
| `createdAt` | string | ISO 8601, может быть null |

Сортировка — по `created_at` DESC. `balance` в корне ответа — текущий `bonus_balance` (int, целые рубли).

### 3.2 GET /api/v1/loyalty-card [AUTH] *(новое)*

Карта лояльности. При первом обращении пользователя без карты сервер создаёт её (lazy-создание) и публикует событие `loyalty_card.created` для подтверждения в 1С.

**Throttle:** 30 запросов/мин (общая группа с `GET /bonuses`).

**Response 200 — карта ещё не подтверждена 1С (`card_confirmed_at` = null):**
```json
{
  "status": "pending",
  "balance": 1500
}
```
Номер карты и QR в статусе `pending` не отдаются (на офлайн-кассе неподтверждённая карта не работает).

**Response 200 — карта активна:**
```json
{
  "status": "active",
  "cardNumber": "2000000012345",
  "qrPayload": "2000000012345",
  "balance": 1500
}
```

| Поле | Тип | Описание |
|---|---|---|
| `status` | string | `pending` \| `active` |
| `cardNumber` | string | Только при `active` |
| `qrPayload` | string | Только при `active`; содержимое для QR-кода (равно `cardNumber`) |
| `balance` | int | Целые рубли |

## 4. Логика начисления/списания *(обновлено)*

- **Списание** — при `bonusAction: "spend"` + `bonusSpendAmount` в `POST /orders` / `POST /client-orders` сервер **резервирует** бонусы (hold), а не списывает сразу. Проверка идёт по `available_bonus_balance`; при нехватке — 422 `{"error": "insufficient_bonus_balance"}`, при невозможности списания — 422 `{"error": "bonus_spend_failed", "message": "Невозможно списать бонусы"}`.
- **Фактическое списание и начисление** — при переходе заказа в статус `Assembled`: холды конвертируются в транзакции `spend`, начисление `earn` рассчитывается от собранного состава.
- **Отмена заказа** — холды освобождаются; для уже списанных денег создаётся транзакция `refund`.
- Offline-операции (кассы 1С) приходят транзакциями `source: "offline"` через шину.

Подробнее про бонусы в заказах — см. `06_orders.md` и `21_client_orders_marketplace.md`.

## 5. Routes

```
Route::middleware(['auth:sanctum', 'throttle:30,1'])->prefix('v1')->group(function () {
    Route::get('bonuses', [BonusController::class, 'index']);
    Route::get('loyalty-card', [LoyaltyCardController::class, 'show']);
});
```
