# ТЗ: Жалоба на неточность в товаре

## 1. Цель

Клиент сообщает о неточности в описании или характеристиках товара. Модератор обрабатывает заявку.

> Эндпоинт работает **только при включённом модуле** `product_issue_reports`. При выключенном — **403** `{"message": "Модуль \"Сообщить о неточности\" отключён"}`.

## 2. Сущность

**ProductIssueReport**: `user_id, product_id, store_id, email, type, message, created_at`.

## 3. Endpoint

### 3.1 POST /api/v1/products/{productId}/report-issue [AUTH]

Создать заявку.

**Throttle:** 5/мин.

**Body:**
```json
{
  "email": "user@example.com",
  "type": "description_error",
  "message": "В описании указан вес 500 г, но упаковка 250 г."
}
```

**Валидация:**
- `email` — required, email, max 255
- `type` — required, string, допустимые значения из `config/product-issues.php`: `description_error` | `missing_characteristics` (каждый форк может задать свой список)
- `message` — required, string, **max 200**

**Response 201:**
```json
{
  "data": {
    "id": 10,
    "productId": 5,
    "email": "user@example.com",
    "type": "description_error",
    "message": "В описании указан вес 500 г, но упаковка 250 г.",
    "createdAt": "2026-04-15T10:30:00+00:00"
  }
}
```

**Ошибки:** 403 (модуль выключен), 422 (валидация).

## 4. Routes

```
Route::middleware('auth:sanctum')->prefix('v1')->group(function () {
    Route::post('products/{product}/report-issue', [ProductIssueReportController::class, 'store'])
        ->middleware('throttle:5,1');
});
```
