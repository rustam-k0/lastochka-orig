# ТЗ: Акции (Promotions)

## 1. Цель

Акции: списки товаров одного магазина с процентом скидки и ограничением по времени.

## 2. Контекст

- **Модуль:** Promotion
- **Источник данных:** админ-панель курьерской системы, входящая синхронизация через RabbitMQ (routing key `merchant_sales`)
- **Авторизация:** все три ручки публичные, без middleware авторизации. Видимость акций B2B-магазинов определяется по пользователю guard'а `sanctum`, который читает Bearer-токен запроса
- **Особенность:** скидка акции применяется к цене товара при выдаче, в БД цена товара не меняется

## 3. Данные

### 3.1 Таблица promotions

```
promotions
├── id: bigint PK
├── store_id: bigint FK → stores.id NOT NULL (ON DELETE CASCADE)
├── source: string NULL                         // источник записи (внешняя система)
├── external_id: string NULL                    // id записи во внешней системе
├── title: string(200) NOT NULL
├── description: text NULL
├── image_path: string(500) NULL
├── discount_percent: tinyint NOT NULL          // ограничение диапазона на уровне БД не задано
├── starts_at: datetime NOT NULL
├── ends_at: datetime NOT NULL
├── is_active: boolean DEFAULT true
├── position: int DEFAULT 0
├── custom_checkboxes: json NULL                // динамические чекбоксы (новое)
├── created_at: timestamp
├── updated_at: timestamp
└── deleted_at: timestamp NULL
```

**Индексы:**
- `store_id, is_active, starts_at, ends_at`
- `is_active, starts_at, ends_at`

### 3.2 Таблица promotion_products (pivot)

```
promotion_products
├── id: bigint PK
├── promotion_id: bigint FK → promotions.id (ON DELETE CASCADE)
├── product_id: bigint FK → products.id (ON DELETE CASCADE)
├── created_at: timestamp
├── updated_at: timestamp
└── UNIQUE (promotion_id, product_id)
```

### 3.3 Ответ API (PromotionResource)

```json
{
  "id": 1,
  "title": "Молочная неделя",
  "description": "Скидки на всю молочную продукцию",
  "image": {
    "id": 1,
    "path": "https://cdn.example.com/promo.jpg"
  },
  "store": {
    "id": 1,
    "name": "Магазин №1",
    "description": "Описание магазина"
  },
  "discountPercent": 15,
  "startsAt": "2026-01-15T00:00:00+03:00",
  "endsAt": "2026-01-22T23:59:59+03:00",
  "productsCount": 25,
  "customCheckboxes": {}
}
```

| Поле | Тип | Комментарий |
|---|---|---|
| `id` | int | |
| `title` | string | |
| `description` | string\|null | Может быть null |
| `image` | object\|null | `{id, path}`, где `id` совпадает с id акции, `path` содержит полный URL. Null, если `image_path` не заполнен |
| `store` | object | `{id, name, description}`. Магазин загружается и в списке, и в деталях акции, поэтому объект присутствует всегда. `description` может быть null (новое: поле `description` добавлено в объект магазина) |
| `discountPercent` | int | Процент скидки акции |
| `startsAt`, `endsAt` | string | ISO 8601 со смещением таймзоны приложения (`Europe/Moscow`, `+03:00`) |
| `productsCount` | int | Количество всех привязанных к акции товаров, без фильтров активности и наличия. Может отличаться от `total` в ручке товаров акции |
| `customCheckboxes` | object | Динамические чекбоксы, map `код флага` → bool (новое). При отсутствии значения отдаётся `{}` |

## 4. Логика

### 4.1 Список акций

**Endpoint:** `GET /api/v1/promotions`

**Query параметры:**
- `page`: int, default 1
- `perPage`: int, default 20, значения больше 100 усекаются до 100
- `storeId`: int, optional (фильтр по магазину)

**Логика:**
1. Отбираются акции с `is_active = true` и `starts_at <= now() <= ends_at`.
2. При включённом модуле `b2b` пользователь без признака `is_b2b` и неавторизованный клиент не получают акции магазинов с `is_b2b = true`. При выключенном модуле фильтр не применяется.
3. При переданном `storeId` добавляется фильтр по магазину.
4. Сортировка: `position` по возрастанию, затем `created_at DESC`.
5. Подгружается магазин.

**Response 200:**
```json
{
  "hasMore": true,
  "data": [PromotionResource, ...],
  "total": 15
}
```

`hasMore` показывает наличие следующей страницы, `total` содержит общее количество акций под фильтром.

### 4.2 Детали акции

**Endpoint:** `GET /api/v1/promotions/{promotion}`

Параметр пути: id акции. Мягко удалённые записи не резолвятся.

**Логика:**
1. Акция ищется по id.
2. Проверяются `is_active = true` и попадание текущего момента в `[starts_at .. ends_at]`.
3. Проверяется видимость акции по B2B-правилу (см. п. 4.1.2). При включённом модуле `b2b` и недоступном магазине (например, мягко удалённом) акция считается невидимой.
4. Подгружается магазин.

**Response 200:** `{"data": {...PromotionResource...}}`.

**Ошибки:**
- 404, тело `{"message": "Акция не найдена или неактивна"}`: акция не найдена, мягко удалена, неактивна, вне периода `[starts_at .. ends_at]` или скрыта B2B-правилом.

### 4.3 Товары акции

**Endpoint:** `GET /api/v1/promotions/{promotion}/products`

**Query параметры:**
- `page`: int, default 1
- `perPage`: int, default 20, значения больше 100 усекаются до 100

**Логика:**
1. Выполняются те же проверки активности и B2B-видимости, что и в п. 4.2.
2. Отбираются товары акции с `is_active = true`, в наличии (остаток покрывает шаг покупки, либо у товара режим `stock_mode = ignore`, либо магазин работает в режиме «не учитывать остатки») и с `price > 0`.
3. Подгружаются изображения товара и счётчики платных добавок.
4. Порядок товаров не задаётся явно и определяется СУБД.

**Response 200:**
```json
{
  "hasMore": true,
  "data": [
    {
      "id": 10,
      "sku": "MILK-32",
      "title": "Молоко 3.2%",
      "price": 80.4,
      "priceOld": 100.5,
      "discountPercent": 20,
      "images": [{"id": 5, "path": "https://cdn.example.com/milk.jpg"}],
      "isFavorite": false,
      "hasSupplements": false,
      "hasRequiredSupplements": false
    }
  ],
  "total": 25
}
```

Поля элемента (`PromotionProductResource`):

| Поле | Тип | Комментарий |
|---|---|---|
| `id`, `sku`, `title` | int, string, string | |
| `price` | float | Цена со скидкой акции, округление до 2 знаков: копейки сохраняются (новое: раньше значение приводилось к целому) |
| `priceOld` | float | Базовая цена: `price_old`, если задана, иначе `price` |
| `discountPercent` | int\|null | Процент скидки акции; при `discount_percent = 0` отдаётся null |
| `images` | array | Элемент: `{id, path}`; у видео дополнительно `type` и `mimeType`. Фильтрация видео по модулю здесь не применяется |
| `isFavorite` | bool | Ручка не подключает middleware авторизации, поэтому Bearer-токен здесь не разрешается в пользователя и значение равно `false` |
| `hasSupplements` | bool | Есть ли у товара платные добавки |
| `hasRequiredSupplements` | bool | Есть ли обязательные добавки (новое) |

`total` считает товары после фильтров активности, наличия и цены, поэтому может быть меньше `productsCount` акции.

### 4.4 Расчёт цены

**В ручке товаров акции** цена считается от базовой цены товара по проценту акции:

```
basePrice = price_old ?? price
price = round(basePrice * (1 - discount_percent / 100), 2)
priceOld = round(basePrice, 2)
discountPercent = discount_percent акции
```

**В остальных ручках каталога** (`ProductResource`, `Product::getEffectivePrice()`) товар может иметь две скидки одновременно: ручную (`price_old` заполнена) и акционную. Применяется большая из них:

```
basePrice = price_old ?? price
manualDiscount = price_old ? (price_old - price) : 0
promoDiscount = round(basePrice * discount_percent / 100, 2)

если promoDiscount >= manualDiscount:
    price = round(basePrice - promoDiscount, 2)
    priceOld = round(basePrice, 2)
    discountPercent = discount_percent акции
иначе:
    price = round(price, 2)
    priceOld = round(price_old, 2)
    discountPercent = round(100 - price / price_old * 100)
```

Активная акция товара выбирается среди его акций с `is_active = true` и текущей датой в `[starts_at .. ends_at]`, при нескольких подходящих берётся акция с максимальным `discount_percent`.

## 5. Использование акций на других страницах

Составных ручек, которые отдавали бы акции вместе с содержимым страницы, нет. Акции запрашиваются отдельно:

- главная страница: `GET /v1/promotions`;
- страница магазина: `GET /v1/promotions?storeId={id}`; ответ `GET /v1/stores/{storeId}` акции не содержит;
- карточка товара: акционная цена уже учтена в полях `price`, `priceOld`, `discountPercent` товара (см. п. 4.4).

## 6. Реализация

### Код
- [x] Миграция `create_promotions_table` (+ `add_source_to_promotions_table`, `add_custom_checkboxes_to_content_entities`)
- [x] Миграция `create_promotion_products_table`
- [x] Модель `Promotion` с factory, scopes `active()`, `forStore()`, `forUserType()`, `ordered()`, метод `isVisibleTo()`
- [x] `PromotionController`
- [x] `PromotionResource`, `PromotionProductResource`
- [x] Расчёт цены в `Product::getEffectivePrice()` и `ProductResource::calculatePriceData()`

### Тесты (`tests/Feature/Api/V1/Promotion/PromotionTest.php`)
- [x] `test_get_promotions`, `test_get_promotions_by_store`, `test_get_promotions_pagination`, `test_get_promotions_sorted_by_position`
- [x] `test_get_promotion`, `test_get_promotion_not_found`, `test_get_inactive_promotion_returns_404`, `test_get_expired_promotion_returns_404`
- [x] `test_get_promotion_products`, `test_get_promotion_products_excludes_inactive`, `test_get_promotion_products_excludes_out_of_stock`
- [x] `test_promotion_price_calculation`, `test_promotion_price_preserves_kopecks`
- [x] `test_manual_discount_vs_promotion`, `test_promotion_discount_greater_than_manual`
- [x] `test_products_count`

### Routes
```php
Route::prefix('v1')->group(function () {
    Route::prefix('promotions')->group(function () {
        Route::get('/', [PromotionController::class, 'index']);
        Route::get('/{promotion}', [PromotionController::class, 'show']);
        Route::get('/{promotion}/products', [PromotionController::class, 'products']);
    });
});
```
