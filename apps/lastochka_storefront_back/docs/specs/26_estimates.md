# ТЗ: Сметы B2B

## 1. Цель

Пользователь (обычно B2B) собирает список товаров в «смету», делится ссылкой с коллегами, добавляет всё в корзину одним кликом, экспортирует в PDF/XLSX.

## 2. Сущности

- **Estimate** — `user_id, name, description?, share_token?, items_count, created_at, updated_at`.
- **EstimateItem** — `estimate_id, product_id, quantity`.

## 3. Endpoints

Все эндпоинты, кроме `GET /estimates/shared/{token}`, защищены `auth:sanctum`.

### 3.1 GET /api/v1/estimates [AUTH]

Список смет пользователя (без пагинации, сортировка по `updated_at` DESC).

**Response 200:** `{"data": [EstimateResource, ...]}`.

### 3.2 POST /api/v1/estimates [AUTH]

Создать смету.

**Body:** `{name, description?}`.

**Валидация:** `name` — required, string, max 255; `description` — nullable, string, max 200.

**Response 201:** `{"data": EstimateResource}`.

**EstimateResource:**

| Поле | Тип | Описание |
|---|---|---|
| `id` | int | |
| `name` | string | |
| `description` | string | Может быть null |
| `itemsCount` | int | Количество позиций |
| `totalAmount` | int | Сумма по эффективным ценам (с учётом акций), усечённая до целых рублей; `0`, если items не загружены |
| `items` | array | `EstimateItemResource[]` — только там, где items загружаются (детали, shared, clone) |
| `createdAt` / `updatedAt` | string | ISO 8601 |

**EstimateItemResource:** `{id: int, product: ProductResource, quantity: int, createdAt: string}`.

### 3.3 GET /api/v1/estimates/{estimate} [AUTH]

Детали сметы (+ items + product.images).

**Response 200:** `{"data": EstimateResource}`. **403** если чужая.

### 3.4 PUT /api/v1/estimates/{estimate} [AUTH]

Обновить смету.

### 3.5 DELETE /api/v1/estimates/{estimate} [AUTH]

Удалить смету.

**Response 204 No Content.**

### 3.6 POST /api/v1/estimates/{estimate}/items [AUTH]

Добавить товар. Если уже есть — увеличить `quantity`.

**Body:** `{productId: int, quantity: int (1..99999)}`.

**Response 201:** `{"data": EstimateItemResource}`.

### 3.7 POST /api/v1/estimates/{estimate}/items/batch [AUTH]

Массовое добавление (до 100 позиций).

**Body:** `{items: [{productId, quantity}, ...]}`.

**Response 200:** `{"added": 5}`.

### 3.8 PUT /api/v1/estimates/{estimate}/items/{item} [AUTH]

Обновить количество.

**Body:** `{quantity: int}`.

### 3.9 DELETE /api/v1/estimates/{estimate}/items/{item} [AUTH]

Удалить позицию.

**Response 204 No Content.**

### 3.10 POST /api/v1/estimates/{estimate}/to-cart [AUTH]

Перенести все товары из сметы в корзину.

**Response 200:**
```json
{
  "added": 3,
  "skipped": 1,
  "skippedProducts": [
    {"id": 5, "title": "Товар", "reason": "out_of_stock"}
  ]
}
```

Возможные `reason`: `not_found`, `out_of_stock`, `store_access_denied`, `unavailable`. Для пустой сметы сервер возвращает `{"added": 0, "skipped": 0, "skippedProducts": []}`.

### 3.11 POST /api/v1/estimates/{estimate}/clone [AUTH]

Создать копию сметы (`name + " (копия)"`).

**Response 201:** `{"data": EstimateResource}`.

### 3.12 POST /api/v1/estimates/{estimate}/share [AUTH]

Получить или создать share-токен (UUID). После вызова `shareToken` гарантированно заполнен.

**Response 200:**
```json
{
  "shareToken": "550e8400-e29b-41d4-a716-446655440000",
  "shareUrl": "https://.../estimates/shared/550e8400-..."
}
```

`shareUrl` — **пользовательская** ссылка (без `/api/v1`), совпадает с шаблоном universal links в AASA (`/estimates/shared/*`). Именно её приложение отдаёт в шаринг «как есть», не собирая ссылку из токена самостоятельно. У получателя с установленным приложением iOS/Android откроют её в приложении; без приложения откроется веб-страница (см. 3.15).

### 3.13 GET /api/v1/estimates/shared/{token}

**Публичный** (без авторизации). Возвращает смету по токену. Это JSON-эндпоинт для приложения; в шаринг эта ссылка не отдаётся.

**Response 200:** `{"data": EstimateResource}` (items + product.images загружены). **404** `{"message": "Смета не найдена"}` если токен невалидный.

### 3.14 GET /api/v1/estimates/{estimate}/export [AUTH]

Экспорт в PDF/XLSX.

**Query:** `format` — `pdf | xlsx`, default `xlsx`.

**Response:**
- 200 `application/pdf` или `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` (streamed download)
- 422 `{"message": "Формат должен быть pdf или xlsx"}`

### 3.15 GET /estimates/shared/{token} (веб-страница, вне /api/v1)

**Публичный** HTML. Целевой адрес universal link из `shareUrl` — открывается у получателей без установленного приложения. Показывает состав сметы (название, товары, количество, цены, итого), кнопку «Открыть в приложении» (кастомная схема) и ссылки на App Store / Google Play. **404** с той же страницей-заглушкой («Смета не найдена») при невалидном токене.

Конфигурация per-fork через ENV (`config/deeplinks.php`, секция `apps`):
- `DEEPLINK_APPSTORE_URL` — ссылка на App Store; из `/id<цифры>` также извлекается app-id для Smart App Banner в Safari
- `DEEPLINK_GOOGLE_PLAY_URL` — ссылка на Google Play
- `DEEPLINK_APP_SCHEME` — кастомная URL-схема приложения (например `electrohub`) для кнопки «Открыть в приложении»; без неё кнопка скрыта

Незаданные значения скрывают соответствующие блоки — страница деградирует до простого просмотра состава сметы.
