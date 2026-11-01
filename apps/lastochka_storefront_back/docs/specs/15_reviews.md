# ТЗ: Отзывы (Reviews)

## 1. Цель

Отзывы на магазины, товары и заказы с расчётом рейтинга магазина и товара.

## 2. Контекст

- **Модуль:** Review
- **Условия создания:**
  - Отзыв на магазин: у пользователя есть заказ в этом магазине со статусом `delivered`
  - Отзыв на товар: пользователь покупал этот товар в доставленном заказе
  - Отзыв на заказ: заказ принадлежит пользователю и имеет статус `delivered`
- **Модерация:** отзывы на магазин и товар создаются неопубликованными (`is_published = false`); публикация выполняется вне storefront и приходит входящим событием RabbitMQ. Отзывы на заказ флага публикации не имеют
- **Расчёт рейтинга:** `stores.rating` и `products.rating` пересчитываются по **всем** отзывам сущности, распределение `ratingsCount` в выдаче считается только по опубликованным

## 3. Данные

### 3.1 Модель StoreReview (отзыв на магазин)

```
store_reviews
├── id: bigint PK
├── store_id: bigint FK → stores.id NOT NULL
├── source: string(50) NULL                    // Источник отзыва (для внешних отзывов)
├── user_id: bigint FK → users.id NULL          // NULL для внешних отзывов
├── order_id: bigint FK → orders.id NULL        // Заказ-основание
├── rating: tinyint NOT NULL                    // 1-5
├── description: text NULL
├── author_name: string(100) NULL               // Краткое имя из профиля
├── author_phone: string(20) NULL               // Телефон из профиля
├── is_published: boolean DEFAULT false
├── published_at: timestamp NULL
├── created_at: timestamp
├── updated_at: timestamp
└── deleted_at: timestamp NULL
```

**Индексы:**
- `store_id, is_published` — для списка отзывов
- `user_id, store_id` — уникальный (один отзыв на магазин)
- `store_id, rating, is_published` — для расчёта рейтинга

Уникальный индекс не учитывает `deleted_at`: soft-deleted отзыв продолжает занимать пару `(user_id, store_id)`.

### 3.2 Модель ProductReview (отзыв на товар)

```
product_reviews
├── id: bigint PK
├── product_id: bigint FK → products.id NOT NULL
├── source: string(50) NULL                     // Источник отзыва (для внешних отзывов)
├── store_id: bigint FK → stores.id NOT NULL    // Денормализация
├── user_id: bigint FK → users.id NULL           // NULL для внешних отзывов
├── order_id: bigint FK → orders.id NULL         // Заказ с этим товаром
├── rating: tinyint NOT NULL                     // 1-5
├── description: text NULL
├── author_name: string(100) NULL
├── author_phone: string(20) NULL
├── is_published: boolean DEFAULT false
├── published_at: timestamp NULL
├── created_at: timestamp
├── updated_at: timestamp
└── deleted_at: timestamp NULL
```

**Индексы:**
- `product_id, is_published`
- `user_id, product_id` — уникальный
- `product_id, rating, is_published`

### 3.3 Модель OrderReview (отзыв на заказ) (новое)

```
order_reviews
├── id: bigint PK
├── order_id: bigint FK → orders.id NOT NULL
├── user_id: bigint FK → users.id NOT NULL
├── rating: tinyint NOT NULL                     // 1-5
├── description: text NULL
├── author_name: string(100) NULL
├── author_phone: string(20) NULL
├── source: string DEFAULT 'storefront'
├── created_at: timestamp
├── updated_at: timestamp
└── deleted_at: timestamp NULL
```

**Индексы:**
- `order_id, user_id` — уникальный (один отзыв на заказ от пользователя)
- `user_id`
- `order_id, rating`

Полей `is_published` / `published_at` нет: отзыв на заказ не модерируется и в публичных выдачах не участвует. Уникальный индекс не учитывает `deleted_at`.

### 3.4 ReviewResource (отзывы на магазин и товар)

```json
{
  "id": 1,
  "rating": 5,
  "description": "Отличный магазин, быстрая доставка!",
  "authorName": "Иван И.",
  "isPublished": true,
  "createdAt": "2024-01-15T10:30:00+00:00"
}
```

`description` и `authorName` могут быть `null`. `updatedAt`, `orderId`, телефон автора в ресурс не входят.

### 3.5 OrderReviewResource (новое)

```json
{
  "id": 7,
  "order_id": 42,
  "rating": 5,
  "description": "Отличная доставка!",
  "author_name": "Иван И.",
  "author_phone": "+79990000000",
  "created_at": "2024-01-15T10:30:00.000000Z",
  "updated_at": "2024-01-15T10:30:00.000000Z"
}
```

Ключи в `snake_case` — в отличие от `ReviewResource`. `description`, `author_name`, `author_phone` могут быть `null`.

### 3.6 Формирование author_name

`author_name` заполняется значением `User::short_name`: имя, плюс первая буква фамилии с точкой, если фамилия задана («Иван И.»). Если `first_name` пустой — `null`. `author_phone` — телефон профиля.

## 4. Логика

### 4.1 Отзывы на магазин

#### getStoreReviews

**Endpoint:** `GET /api/v1/stores/{store}/reviews`

Публичная ручка, авторизация не требуется. `{store}` — id магазина (route model binding), несуществующий id даёт 404.

**Query параметры:**
- `page` — int, default 1
- `perPage` — int, default 20, значения больше 100 приводятся к 100

**Поведение сервера:**
1. Выбираются отзывы магазина с `is_published = true`, сортировка `published_at DESC`.
2. `averageRating` берётся из поля `stores.rating` — оно пересчитывается по **всем** отзывам магазина, включая неопубликованные.
3. `ratingsCount` считается только по опубликованным отзывам; ключи `"5"`…`"1"` присутствуют всегда, отсутствующие оценки отдаются нулями.

**Ответ 200** (плоский объект, без обёртки `{data: ...}`):
```json
{
  "hasMore": true,
  "data": [
    {
      "id": 1,
      "rating": 5,
      "description": "Отличный магазин, быстрая доставка!",
      "authorName": "Иван И.",
      "isPublished": true,
      "createdAt": "2024-01-15T10:30:00+00:00"
    }
  ],
  "total": 50,
  "averageRating": "4.5",
  "ratingsCount": {
    "5": 30,
    "4": 12,
    "3": 5,
    "2": 2,
    "1": 1
  }
}
```

> `averageRating` отдаётся **строкой** с одним знаком после запятой (`"4.5"`) — поле `stores.rating` имеет каст `decimal:1`; может быть `null`, если у магазина нет отзывов. В `ProductResource` то же значение приводится к числу, поэтому форматы отличаются. `description`, `authorName` — могут быть `null`. `isPublished` всегда присутствует и в этой выдаче всегда `true`.

#### createStoreReview [AUTH]

**Endpoint:** `POST /api/v1/stores/{store}/reviews`

**Входные данные:**
```json
{
  "rating": 5,
  "description": "Отличный магазин!"
}
```

**Валидация** (`CreateReviewRequest`):
- `rating` — required, integer, min 1, max 5
- `description` — nullable, string, max 2000
- Сообщения: «Укажите оценку», «Оценка должна быть числом», «Минимальная оценка — 1», «Максимальная оценка — 5», «Текст отзыва не должен превышать 2000 символов»

**Поведение сервера:**
1. Проверяется право на отзыв: есть заказ пользователя в этом магазине со статусом `delivered` **и** нет его отзыва на магазин.
2. Если права нет — различаются два случая: при существующем отзыве возвращается 422, иначе 403.
3. Отзыв сохраняется с `is_published = false`, `author_name = User::short_name`, `author_phone = user.phone`. Поле `order_id` при создании из storefront не заполняется.
4. Если по паре `(user_id, store_id)` есть soft-deleted отзыв, он восстанавливается и перезаписывается вместо создания новой строки (уникальный индекс не учитывает `deleted_at`, ALADDIN-11937).
5. Пересчитывается рейтинг магазина: `AVG(rating)` с округлением до 1 знака и `COUNT(*)` по всем не удалённым отзывам магазина, включая неопубликованные; при отсутствии отзывов `rating = null`.

**HTTP:** `201 Created`, тело — `ReviewResource` **без** обёртки `{"data": ...}`:
```json
{
  "id": 10,
  "rating": 5,
  "description": "Отличный магазин!",
  "authorName": "Иван И.",
  "isPublished": false,
  "createdAt": "2024-01-15T10:30:00+00:00"
}
```

**Ошибки:**
- 403 — нет доставленных заказов в этом магазине (`CannotReviewException`):
```json
{"message": "Нет доставленных заказов в этом магазине"}
```
- 422 — отзыв уже существует (`ReviewAlreadyExistsException`), в том числе при гонке с параллельным созданием отзыва:
```json
{
  "message": "Вы уже оставляли отзыв",
  "errors": {"review": ["Вы уже оставляли отзыв"]}
}
```
- 422 — ошибка валидации `rating` / `description`
- 401 — запрос без авторизации

#### updateStoreReview [AUTH]

**Endpoint:** `PUT /api/v1/stores/{store}/reviews`

**Входные данные:**
```json
{
  "rating": 4,
  "description": "Обновлённый отзыв"
}
```

**Валидация** (`UpdateReviewRequest`): те же правила и сообщения, что при создании — `rating` обязателен, `description` — nullable, max 2000.

**Поведение сервера:**
1. Ищется отзыв текущего пользователя на этот магазин (soft-deleted не учитываются).
2. Обновляются `rating` и `description`; если `description` не передан, он записывается как `null`.
3. `is_published` при обновлении не изменяется — опубликованный отзыв остаётся опубликованным.
4. Пересчитывается рейтинг магазина.

**HTTP:** `200 OK`, тело — `ReviewResource` **без** обёртки `{"data": ...}`.

**Ошибки:**
- 404 — отзыв не найден (`{"message": "Отзыв не найден"}`)
- 422 — ошибка валидации
- 401 — запрос без авторизации

#### getMyStoreReview [AUTH]

**Endpoint:** `GET /api/v1/stores/{store}/reviews/my`

**Поведение сервера:** возвращается отзыв текущего пользователя на магазин (независимо от `is_published`) и флаг `canReview` — есть доставленный заказ в этом магазине и ещё нет отзыва.

**Ответ 200:**
```json
{
  "review": {
    "id": 1,
    "rating": 5,
    "description": "Отличный магазин!",
    "authorName": "Иван И.",
    "isPublished": true,
    "createdAt": "2024-01-15T10:30:00+00:00"
  },
  "canReview": false
}
```

Если отзыва нет:
```json
{
  "review": null,
  "canReview": true
}
```

### 4.2 Отзывы на товар

#### getProductReviews

**Endpoint:** `GET /api/v1/products/{product}/reviews`

Публичная ручка, авторизация не требуется. `{product}` — id товара, несуществующий id даёт 404.

**Query параметры:**
- `page` — int, default 1
- `perPage` — int, default 20, значения больше 100 приводятся к 100

**Поведение сервера:** выбираются отзывы товара с `is_published = true`, сортировка `published_at DESC`. `averageRating` берётся из `products.rating` (пересчитывается по всем отзывам товара), `ratingsCount` — по опубликованным.

**Ответ 200:** структура совпадает с `getStoreReviews`. `averageRating` так же отдаётся строкой (`"4.5"`) или `null` — каст `decimal:1`.

#### createProductReview [AUTH]

**Endpoint:** `POST /api/v1/products/{product}/reviews`

**Входные данные:**
```json
{
  "rating": 5,
  "description": "Вкусное молоко!"
}
```

**Валидация:** `CreateReviewRequest` — правила и сообщения те же, что для отзыва на магазин.

**Поведение сервера:**
1. Проверяется право на отзыв: товар есть в `order_items` заказа пользователя со статусом `delivered` **и** нет его отзыва на этот товар.
2. Отзыв сохраняется с `is_published = false`, `store_id` берётся из товара, `author_name = User::short_name`, `author_phone = user.phone`; `order_id` не заполняется.
3. Soft-deleted отзыв по паре `(user_id, product_id)` восстанавливается и перезаписывается.
4. Пересчитывается рейтинг товара по тем же правилам, что и рейтинг магазина.

**HTTP:** `201 Created`, тело — `ReviewResource` без обёртки `{"data": ...}`.

**Ошибки:**
- 403 — товар не покупался (`CannotReviewException`): `{"message": "Вы не покупали этот товар"}`
- 422 — отзыв уже существует (`ReviewAlreadyExistsException`), в том числе при гонке
- 422 — ошибка валидации
- 401 — запрос без авторизации

#### updateProductReview [AUTH]

**Endpoint:** `PUT /api/v1/products/{product}/reviews`

**Поведение сервера:** совпадает с `updateStoreReview` — ищется отзыв текущего пользователя на товар, обновляются `rating` и `description`, `is_published` не изменяется, пересчитывается рейтинг товара. Ответ — `ReviewResource` без обёртки, 404 `{"message": "Отзыв не найден"}` при отсутствии отзыва.

#### getMyProductReview [AUTH]

**Endpoint:** `GET /api/v1/products/{product}/reviews/my`

**Поведение сервера:** совпадает с `getMyStoreReview` — `{review: ReviewResource|null, canReview: bool}`, где `canReview` учитывает факт покупки товара в доставленном заказе.

### 4.3 Отзывы на заказ (новое)

#### getOrderReview [AUTH]

**Endpoint:** `GET /api/v1/orders/{order}/review`

**Поведение сервера:** возвращается отзыв на заказ, принадлежащий текущему пользователю. Владение самим заказом отдельно не проверяется: чужой отзыв не проходит фильтр по `user_id`, и ответ будет 404.

**Ответ 200:**
```json
{
  "data": {
    "id": 7,
    "order_id": 42,
    "rating": 5,
    "description": "Отличная доставка!",
    "author_name": "Иван И.",
    "author_phone": "+79990000000",
    "created_at": "2024-01-15T10:30:00.000000Z",
    "updated_at": "2024-01-15T10:30:00.000000Z"
  }
}
```

**Ошибки:**
- 404 — `{"message": "Отзыв не найден"}`
- 401 — запрос без авторизации

#### createOrderReview [AUTH]

**Endpoint:** `POST /api/v1/orders/{order}/review`

**Ограничение частоты:** `throttle:10,1` — 10 запросов в минуту.

**Входные данные:**
```json
{
  "rating": 5,
  "description": "Отличная доставка!"
}
```

**Валидация** (`CreateOrderReviewRequest`):
- `rating` — required, integer, min 1, max 5
- `description` — nullable, string, **max 1000** (лимит короче, чем у отзывов на магазин и товар)
- Сообщения: «Поле "Рейтинг" обязательно для заполнения», «Рейтинг должен быть числом», «Рейтинг не может быть меньше 1», «Рейтинг не может быть больше 5», «Описание не может быть длиннее 1000 символов»

**Поведение сервера:**
1. Авторизация выполняется в `OrderReviewPolicy::createForOrder`: заказ принадлежит текущему пользователю и его статус — `delivered`. Иначе — 403.
2. Отзыв сохраняется с `author_name = User::short_name`, `author_phone = user.phone`, `source = 'storefront'`.
3. Нарушение уникального индекса `(order_id, user_id)` преобразуется в 422. Индекс не учитывает `deleted_at`, поэтому повторное создание отзыва после его удаления также даёт 422.
4. Рейтинги магазина и товара по отзывам на заказ не пересчитываются.

**HTTP:** `201 Created`, тело — `{"data": OrderReviewResource}`.

**Ошибки:**
- 403 — заказ чужой или не в статусе `delivered`; тело — стандартный отказ Laravel: `{"message": "This action is unauthorized."}`
- 422 — отзыв на заказ уже существует (`OrderAlreadyHasReviewException`):
```json
{
  "message": "На этот заказ уже оставлен отзыв",
  "errors": {"review": ["На этот заказ уже оставлен отзыв"]}
}
```
- 422 — ошибка валидации
- 429 — превышено ограничение частоты
- 401 — запрос без авторизации

#### updateOrderReview [AUTH]

**Endpoint:** `PUT /api/v1/orders/{order}/review`

**Ограничение частоты:** `throttle:10,1` — 10 запросов в минуту.

**Валидация** (`UpdateOrderReviewRequest`): правила и сообщения те же, что при создании, включая `description` max 1000.

**Поведение сервера:**
1. Берётся отзыв заказа; если его нет — 404. Если отзыв принадлежит другому пользователю — 403 на этапе авторизации запроса.
2. Обновляются `rating` и `description`; не переданный `description` записывается как `null`.

**HTTP:** `200 OK`, тело — `{"data": OrderReviewResource}`.

**Ошибки:**
- 404 — `{"message": "Отзыв не найден"}`
- 403 — отзыв принадлежит другому пользователю
- 422 — ошибка валидации
- 429 — превышено ограничение частоты
- 401 — запрос без авторизации

### 4.4 Все мои отзывы [AUTH] (новое)

**Endpoint:** `GET /api/v1/reviews/my`

**Query параметры:**
- `page` — int, default 1, значения меньше 1 приводятся к 1
- `perPage` — int, default 20, приводится к диапазону 1…100

**Поведение сервера:**
1. Объединяются отзывы текущего пользователя на **товары** и на **заказы**. Отзывы на магазины в эту выдачу не входят.
2. Удалённые (soft-deleted) отзывы исключаются.
3. Сортировка — `created_at DESC`, при равенстве — `id DESC`.
4. `total` — суммарное количество отзывов обоих типов, `hasMore` — есть ли записи за пределами текущей страницы.
5. Публикация не учитывается: неопубликованные отзывы на товары также возвращаются.

**Ответ 200** (плоский объект, без обёртки `{data: ...}`; внутри `data` — массив `MyReviewResource`):
```json
{
  "hasMore": false,
  "data": [
    {
      "id": 12,
      "type": "product",
      "targetId": 55,
      "targetTitle": "Молоко 3.2%",
      "targetImageUrl": "https://cdn.example.com/milk.jpg",
      "rating": 5,
      "description": "Вкусное молоко!",
      "createdAt": "2024-01-15T10:30:00+00:00",
      "updatedAt": "2024-01-15T10:30:00+00:00",
      "canEdit": true,
      "canDelete": false
    },
    {
      "id": 7,
      "type": "order",
      "targetId": 42,
      "targetTitle": "Заказ №42",
      "targetImageUrl": null,
      "rating": 5,
      "description": "Отличная доставка!",
      "createdAt": "2024-01-14T09:00:00+00:00",
      "updatedAt": "2024-01-14T09:00:00+00:00",
      "canEdit": true,
      "canDelete": false
    }
  ],
  "total": 2
}
```

**Поля `MyReviewResource`:**
- `type` — `"product"` или `"order"`
- `targetId` — `product_id` для отзыва на товар, `order_id` для отзыва на заказ
- `targetTitle` — название товара (может быть `null`) либо строка `«Заказ №{id}»`
- `targetImageUrl` — полный URL первого изображения товара, может быть `null`; для отзыва на заказ всегда `null`
- `description` — может быть `null`
- `canEdit` — константа `true`
- `canDelete` — константа `false` (ручки удаления отзыва в API нет)

**Ошибки:**
- 401 — запрос без авторизации

### 4.5 Расчёт рейтинга

**Магазин** (`ReviewService::recalculateStoreRating`) — вызывается при создании и обновлении отзыва на магазин:

```php
$stats = StoreReview::query()
    ->where('store_id', $store->id)
    ->selectRaw('AVG(rating) as avg_rating, COUNT(*) as count')
    ->first();

$store->update([
    'rating' => $stats->avg_rating ? round((float) $stats->avg_rating, 1) : null,
    'reviews_count' => $stats->count ?? 0,
]);
```

Фильтра `is_published` в пересчёте нет: `stores.rating` и `stores.reviews_count` учитывают все не удалённые отзывы магазина.

**Поля в stores:**
```
stores
├── rating: decimal(2,1) NULL            // Средний рейтинг по всем отзывам
├── reviews_count: int DEFAULT 0         // Количество всех отзывов
```

**Товар** (`ReviewService::recalculateProductRating`) — аналогично, по `product_reviews`.

**Поля в products:**
```
products
├── rating: decimal(2,1) NULL
├── reviews_count: int DEFAULT 0
```

### 4.6 Проверка права на отзыв

**Отзыв на магазин** (`ReviewService::canReviewStore`):
```php
$hasDeliveredOrder = Order::query()
    ->where('user_id', $user->id)
    ->where('store_id', $store->id)
    ->where('status', OrderStatus::Delivered)
    ->exists();

if (! $hasDeliveredOrder) {
    return false;
}

$hasReview = StoreReview::query()
    ->where('user_id', $user->id)
    ->where('store_id', $store->id)
    ->exists();

return ! $hasReview;
```

**Отзыв на товар** (`ReviewService::canReviewProduct`):
```php
$hasPurchased = OrderItem::query()
    ->whereHas('order', function ($query) use ($user) {
        $query->where('user_id', $user->id)
            ->where('status', OrderStatus::Delivered);
    })
    ->where('product_id', $product->id)
    ->exists();

if (! $hasPurchased) {
    return false;
}

$hasReview = ProductReview::query()
    ->where('user_id', $user->id)
    ->where('product_id', $product->id)
    ->exists();

return ! $hasReview;
```

Обе проверки не учитывают soft-deleted отзывы: после удаления отзыва `canReview` снова становится `true`, а создание восстанавливает удалённую строку.

**Отзыв на заказ** (`OrderReviewPolicy::createForOrder`): заказ принадлежит пользователю и имеет статус `delivered`. Наличие дубликата политикой не проверяется — его отсекает уникальный индекс с ответом 422.

## 5. Реализация

### Код
- Миграции `create_store_reviews_table`, `create_product_reviews_table`, `create_order_reviews_table`, `add_source_to_store_reviews_table`, `add_source_to_product_reviews_table`, `add_reviews_count_to_stores_table`, `add_rating_to_products_table`, `backfill_review_user_ids_by_phone`
- Модели `StoreReview`, `ProductReview`, `OrderReview` (все с soft delete)
- `StoreReviewController`, `ProductReviewController`, `OrderReviewController`, `MyReviewsController`
- `ReviewResource`, `MyReviewResource`, `OrderReviewResource`
- `CreateReviewRequest`, `UpdateReviewRequest`, `CreateOrderReviewRequest`, `UpdateOrderReviewRequest`
- `CreateStoreReviewAction`, `UpdateStoreReviewAction`, `CreateProductReviewAction`, `UpdateProductReviewAction`, `CreateOrderReviewAction`, `UpdateOrderReviewAction`
- `ReviewService` (проверка права, пересчёт рейтинга, распределение оценок)
- `OrderReviewPolicy` — авторизация создания и обновления отзыва на заказ. `ReviewPolicy` в коде присутствует, но в обработке запросов не участвует: контроллеры отзывов на магазин и товар ищут отзыв сразу по `user_id` текущего пользователя
- Исключения `CannotReviewException` (403), `ReviewAlreadyExistsException` (422), `OrderAlreadyHasReviewException` (422), `OrderReviewNotOwnedException` (403)

### Тесты
- `tests/Feature/StoreReviewDuplicateFixTest.php` — повторное создание после soft delete, 422 при существующем отзыве, привязка входящих отзывов к пользователю по телефону
- `tests/Feature/OrderReviewTest.php` — создание для доставленного заказа, 403 для недоставленного и чужого, обновление, чтение, 422 при дубликате
- `tests/Feature/MyReviewsTest.php` — 401 без авторизации, выборка только своих отзывов, смешивание типов и сортировка

### Routes
```php
Route::prefix('v1')->group(function () {
    // Все мои отзывы (товары + заказы)
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('reviews/my', [MyReviewsController::class, 'index']);
    });

    // Отзывы на магазин
    Route::get('stores/{store}/reviews', [StoreReviewController::class, 'index']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('stores/{store}/reviews/my', [StoreReviewController::class, 'my']);
        Route::post('stores/{store}/reviews', [StoreReviewController::class, 'store']);
        Route::put('stores/{store}/reviews', [StoreReviewController::class, 'update']);
    });

    // Отзывы на товар
    Route::get('products/{product}/reviews', [ProductReviewController::class, 'index']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('products/{product}/reviews/my', [ProductReviewController::class, 'my']);
        Route::post('products/{product}/reviews', [ProductReviewController::class, 'store']);
        Route::put('products/{product}/reviews', [ProductReviewController::class, 'update']);
    });

    // Отзывы на заказ
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('orders/{order}/review', [OrderReviewController::class, 'show']);
        Route::post('orders/{order}/review', [OrderReviewController::class, 'store'])
            ->middleware('throttle:10,1');
        Route::put('orders/{order}/review', [OrderReviewController::class, 'update'])
            ->middleware('throttle:10,1');
    });
});
```

Ограничение частоты установлено только на создание и обновление отзыва на заказ. Остальные ручки отзывов ограничений частоты не имеют.
