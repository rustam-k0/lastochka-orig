# ТЗ: Дополнение заказа (Order Supplement)

## 1. Цель

Два API endpoint для Flutter-клиента:
1. **Проверка возможности дополнения** — возвращает ID заказа в статусе `pending` для конкретного магазина, если таковой есть
2. **Дополнение заказа** — добавляет товары из текущей корзины к существующему заказу

---

## 2. Контекст

### Где живёт
- Модуль: `Order`
- Контроллер: `OrderController` (расширение) или отдельный `OrderSupplementController`
- Action: `SupplementOrderAction`

### Триггер
- API call от Flutter-клиента
- Пользователь находится в магазине, у него есть активный заказ в статусе `pending`

### Связь с системой
- Использует существующую корзину (`Cart`, `CartItem`)
- Работает с `Order`, `OrderItem`
- Переиспользует `ReserveProductsAction` для резервирования товаров
- Публикует событие `OrderSupplemented` → RabbitMQ для синхронизации с CRM

---

## 3. Данные

### Endpoint 1: Проверка возможности дополнения

**Request:**
```
GET /api/v1/orders/check-supplement
Authorization: Bearer <token>
```

Query params:
| Параметр | Тип | Обязательный | Описание |
|----------|-----|--------------|----------|
| store_id | int | ✅ | ID магазина |

**Response (200):**
```json
{
  "order_id": 123,
  "can_supplement": true
}
```

Или если нет заказа для дополнения:
```json
{
  "order_id": null,
  "can_supplement": false
}
```

**Логика:**
1. Найти заказ пользователя для `store_id` со статусом `pending`
2. Если найден — вернуть `order_id`, иначе `null`

---

### Endpoint 2: Дополнение заказа

**Request:**
```
POST /api/v1/orders/{order_id}/supplement
Authorization: Bearer <token>
Content-Type: application/json
```

Body:
```json
{
  "idempotency_key": "uuid-v4"
}
```

| Параметр | Тип | Обязательный | Описание |
|----------|-----|--------------|----------|
| idempotency_key | string | ❌ | Ключ идемпотентности (UUID) |

**Response (200):**
```json
{
  "data": {
    "id": 123,
    "store": { ... },
    "status": "pending",
    "items": [...],
    "subtotal": 1500,
    "discount": 50,
    "deliveryCost": 200,
    "total": 1650,
    "itemsCount": 5
  }
}
```

Возвращает `{data: OrderResource}`. ⚠️ **Ключи в ответе — camelCase** (`deliveryCost`, `itemsCount`), несмотря на snake_case в request body. Обёртка `{data}` добавляется автоматически.

**Коды ошибок:**
| Код | Ситуация |
|-----|----------|
| 403 | Заказ не принадлежит пользователю **ИЛИ** статус ≠ pending (OrderPolicy отклоняет оба случая как 403) |
| 409 | Race condition: статус изменился на не-pending между policy-check и транзакцией |
| 422 | Корзина пуста для этого магазина |
| 422 | Недостаточно остатков товара |

> `idempotency_key` в body — **snake_case** (расходится с camelCase `idempotencyKey` в `POST /orders`). Легаси.

---

## 4. Логика

### Happy Path (Endpoint 2)

```
1. Проверить, что заказ принадлежит текущему пользователю
2. Проверить, что статус заказа = pending
3. Получить корзину пользователя
4. Получить CartItem для store_id заказа
5. Если корзина пуста — вернуть 422
6. Для каждого товара в корзине:
   a. Проверить остатки (ReserveProductsAction)
   b. Если товар уже есть в заказе — увеличить quantity
   c. Если нет — создать новый OrderItem (snapshot)
7. Пересчитать:
   - items_count (общее количество позиций)
   - subtotal (сумма всех товаров)
   - total = subtotal - discount + delivery_cost (discount и delivery_cost НЕ пересчитываются)
8. Очистить корзину для этого магазина
9. Опубликовать событие OrderSupplemented
10. Вернуть обновлённый Order
```

### Edge Cases

| Ситуация | Поведение |
|----------|-----------|
| Товар уже в заказе | Увеличить `quantity` и `total` в существующем `OrderItem` |
| Несколько заказов `pending` для магазина | Взять самый свежий (ORDER BY created_at DESC LIMIT 1) |
| Заказ перешёл в `assembling` между проверкой и дополнением | Вернуть 409 Conflict |
| Недостаточно остатков | Вернуть 422 с указанием какого товара не хватает |
| Повторный запрос с тем же `idempotency_key` | Вернуть текущее состояние заказа (идемпотентность) |

### Error Handling

- Все операции в рамках одной DB-транзакции
- При ошибке резервирования — откат, товары не списываются
- Логирование ошибок в стандартный Laravel log

---

## 5. Интеграции

### RabbitMQ

При успешном дополнении публикуется событие:

**Exchange:** `storefront`
**Routing key:** `order.supplemented`

```json
{
  "order_id": 123,
  "external_id": "CRM-123",
  "added_items": [
    {
      "product_id": 10,
      "sku": "SKU-001",
      "title": "Молоко 3.2%",
      "price": 89,
      "quantity": 2,
      "total": 178
    }
  ],
  "new_subtotal": 1500,
  "new_total": 1650,
  "timestamp": "2024-01-15T10:30:00.000Z"
}
```

**Важно:** CRM должна обработать это событие и обновить заказ на своей стороне.

---

## 6. Нефункциональные требования

### Транзакции
- Дополнение заказа — атомарная операция
- Резервирование товаров + создание OrderItem + обновление Order в одной транзакции

### Идемпотентность
- Поддерживается через `idempotency_key`
- Хранить в отдельной таблице `order_supplement_idempotency` или использовать существующий механизм
- TTL: 24 часа

### Безопасность
- Авторизация: только владелец заказа может его дополнять
- Проверка через `OrderPolicy::supplement()`

### Race Condition
- Между проверкой статуса и дополнением статус может измениться
- Решение: повторная проверка внутри транзакции с `FOR UPDATE` на записи заказа

---

## 7. Ожидаемый результат

### Код
- [ ] `App\Http\Controllers\Api\V1\OrderSupplementController` или методы в `OrderController`
- [ ] `App\Actions\Order\SupplementOrderAction`
- [ ] `App\Http\Requests\Api\V1\Order\CheckSupplementRequest`
- [ ] `App\Http\Requests\Api\V1\Order\SupplementOrderRequest`
- [ ] `App\Events\Order\OrderSupplemented`
- [ ] `App\RabbitMQ\Transformers\OrderSupplementedTransformer`
- [ ] Дополнение `OrderPolicy` методом `supplement()`

### Миграции
- [ ] (опционально) Таблица `order_supplement_idempotency` если не используем существующий механизм

### Тесты
- [ ] `tests/Feature/Api/V1/Order/OrderSupplementTest.php`
  - Проверка наличия заказа для дополнения
  - Успешное дополнение
  - Дополнение с уже существующим товаром (увеличение quantity)
  - Попытка дополнить чужой заказ (403)
  - Попытка дополнить заказ не в статусе pending (409)
  - Пустая корзина (422)
  - Идемпотентность
  - Недостаточно остатков (422)

### Документация
- [ ] Обновить `docs/api_docs.md` — добавить новые endpoints

