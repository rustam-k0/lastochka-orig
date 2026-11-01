# ТЗ: Заявки «Под заказ»

## 1. Цель

Клиент оставляет заявку на товар, которого нет в наличии. Менеджер связывается и оформляет при поступлении.

> Эндпоинты работают **только при включённом модуле** `preorders` в `projectModules`. При выключенном — **403** `{"message": "Модуль \"Под заказ\" отключён"}`.

## 2. Сущность

**Preorder**: `user_id, product_id, store_id, full_name, phone, email?, quantity, comment?, status (enum PreorderStatus: new|completed|cancelled), created_at`.

## 3. Endpoints

### 3.1 POST /api/v1/products/{productId}/preorders [AUTH]

Создать заявку на товар.

**Throttle:** 5/мин.

**Body (camelCase — поле переименовано в `fullName`):**
```json
{
  "fullName": "Иван Иванов",
  "phone": "+79991234567",
  "email": "ivan@example.com",
  "quantity": 3,
  "comment": "Позвоните перед доставкой"
}
```

**Валидация:**
- `fullName` — required, string, max 255
- `phone` — required, string, max 20
- `email` — nullable, email, max 255
- `quantity` — required, integer, min 1
- `comment` — nullable, string, max 1000

**Response 201** (связь product при создании не загружена — `productTitle`/`productImage` отсутствуют):
```json
{
  "data": {
    "id": 42,
    "productId": 5,
    "storeId": 1,
    "fullName": "Иван Иванов",
    "phone": "+79991234567",
    "email": "ivan@example.com",
    "quantity": 3,
    "comment": "Позвоните перед доставкой",
    "status": "new",
    "createdAt": "2026-04-15T10:30:00+00:00"
  }
}
```

Поля `productTitle` / `productImage` присутствуют только когда загружена связь product (в `GET /preorders` — присутствуют, `productImage` может быть null). `email`, `comment` могут быть null.

**Ошибки:** 403 (модуль выключен), 422 (валидация).

### 3.2 GET /api/v1/preorders [AUTH]

Мои заявки.

**Query:**
- `page` — int, default 1
- `perPage` — int, default 20, max 50
- `status` — optional, enum `new|completed|cancelled`

**Response 200:** `{hasMore, data: PreorderResource[], total}`.

## 4. Routes

```
Route::middleware('auth:sanctum')->prefix('v1')->group(function () {
    Route::get('preorders', [PreorderController::class, 'index']);
    Route::post('products/{product}/preorders', [PreorderController::class, 'store'])
        ->middleware('throttle:5,1');
});
```
