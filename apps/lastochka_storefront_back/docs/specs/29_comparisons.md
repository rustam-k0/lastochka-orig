# ТЗ: Сравнение товаров

## 1. Цель

Пользователь собирает товары в группы сравнения. Одна группа = одна категория товара: при добавлении товар автоматически попадает в группу своей категории.

## 2. Сущности

- **Comparison** — группа сравнения пользователя (`user_id`, `category_id`).
- **ComparisonItem** — товар в группе (уникален по паре user+product, добавление идемпотентно).

## 3. База

| | |
|---|---|
| Префикс | `/api/v1/comparisons` |
| Auth | `Authorization: Bearer <token>` — все ручки `[AUTH]` |
| Throttle | 60/мин на всю группу |

## 4. Endpoints

### 4.1 GET /api/v1/comparisons [AUTH]

Список групп сравнения пользователя. Сортировка по дате создания группы DESC. Категория группы читается включая удалённые (`withTrashed`) — группа не пропадает при удалении категории.

**Response 200:**
```json
{
  "data": [
    { "categoryId": 12, "categoryTitle": "Смартфоны", "count": 3 }
  ]
}
```

### 4.2 GET /api/v1/comparisons/{categoryId} [AUTH]

Детали группы: товары + объединение (UNION) атрибутов всех товаров группы.

**Response 200:**
```json
{
  "data": {
    "categoryId": 12,
    "categoryTitle": "Смартфоны",
    "products": [
      {
        "id": 5,
        "title": "Товар",
        "price": 999.99,
        "imageUrl": "https://.../image.jpg",
        "available": true,
        "attributes": [
          { "code": "color", "value": "красный" }
        ]
      }
    ],
    "attributes": [
      { "code": "color", "name": "Цвет", "position": 1 }
    ]
  }
}
```

- `price` — float с копейками (эффективная цена с учётом акций).
- `imageUrl` — может быть null.
- `attributes` товара — только `code`/`value`; полный список колонок сравнения (с названиями) — в корневом `attributes`.
- Товары читаются включая удалённые (`withTrashed`); `available` = false для недоступных.

**Ошибки:** 404 — группа по этой категории у пользователя не найдена.

### 4.3 POST /api/v1/comparisons/items [AUTH]

Добавить товар в сравнение. Идемпотентно по (user, product) — повторное добавление не создаёт дубль.

**Body:**

| Поле | Тип | Обяз. | Описание |
|---|---|---|---|
| `productId` | int | да | Должен существовать в `products` |

**Response 201:**
```json
{
  "data": { "categoryId": 12, "productId": 5, "count": 3 }
}
```

`count` — количество товаров в группе после добавления. Группа определяется категорией товара автоматически.

**Ошибки:**
- 422 — валидация (`productId` отсутствует/не существует) или у товара отсутствует категория (`{"message": ..., "errors": {"productId": [...]}}`).

### 4.4 DELETE /api/v1/comparisons/items/{productId} [AUTH]

Удалить товар из сравнения по ID товара.

**Response 204** — без тела.

**Ошибки:** 404 — товара в сравнении нет.

### 4.5 DELETE /api/v1/comparisons/{categoryId} [AUTH]

Удалить группу сравнения целиком.

**Response 204** — без тела.

**Ошибки:** 404 — группы нет.

## 5. Routes

```
Route::middleware(['auth:sanctum', 'throttle:60,1'])->prefix('comparisons')->group(function () {
    Route::get('/', [ComparisonController::class, 'index']);
    Route::get('/{categoryId}', [ComparisonController::class, 'show'])->whereNumber('categoryId');
    Route::post('/items', [ComparisonController::class, 'addItem']);
    Route::delete('/items/{productId}', [ComparisonController::class, 'removeItem'])->whereNumber('productId');
    Route::delete('/{categoryId}', [ComparisonController::class, 'clear'])->whereNumber('categoryId');
});
```
