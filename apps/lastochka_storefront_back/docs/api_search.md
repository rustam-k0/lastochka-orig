# API Documentation: Search (Поиск товаров)

> **Figma**: [Поиск товара](https://www.figma.com/design/tQWH5AzFUWxUaNefH5P9Wo/%D0%90%D0%BB%D0%B0%D0%B4%D0%B4%D0%B8%D0%BD--Copy-?node-id=958-7239&m=dev)
>
> Документ сверен с кодом 2026-07-29: `routes/api.php` (группа `search/*`), `SearchController`,
> `SearchProductsRequest` / `SearchProductsInStoreRequest` / `SearchSuggestionsRequest` /
> `SearchRecommendationsRequest`, `SearchService`, `MeilisearchClient`, `config/meilisearch.php`,
> `ProductResource` / `StoreResource` / `CategoryResource` / `ImageResource`.

---

## Описание функционала

Поиск товаров по названию и SKU с группировкой результатов по магазинам. Ответ содержит список
магазинов, найденные в каждом товары и секцию категорий, название которых совпало с запросом.

**UI элементы (контекст Figma, не часть контракта):**
- Поле поиска с текстовым запросом
- Количество найденных результатов (общее)
- Список магазинов с информацией о каждом
- Горизонтальный список товаров под каждым магазином

---

## Общие правила группы `search/*`

- Префикс всех маршрутов — `/api/v1/search/…`.
- На всю группу навешен `throttle:100,1` — 100 запросов в минуту, при превышении HTTP 429.
- Авторизация опциональная и нигде не обязательна:
  - `GET /search/products` и `GET /search/recommendations` проходят через middleware `OptionalAuth`
    (читает Bearer-токен Sanctum, при невалидном токене запрос продолжается как гостевой);
  - `GET /search/suggestions` берёт пользователя напрямую через guard `sanctum` (middleware нет,
    поведение то же: без токена или с невалидным токеном — как гость);
  - `GET /search/stores/{store}/products` пользователя не учитывает вообще.
- Ошибки валидации — HTTP 422 в стандартном формате Laravel (`{message, errors}`) для запросов
  с `Accept: application/json`.
- Недоступность Meilisearch не приводит к 500: товарная часть выдачи деградирует до пустой
  (HTTP 200), ошибка пишется в лог. Секция `categories` эндпоинта `/search/products` строится
  по БД и остаётся заполненной даже при упавшем Meilisearch.

---

## Модели данных

### Store (`StoreResource` в контексте поиска)

В поиске ресурс отдаётся без `deliveryInfo` и без блока самовывоза, отношение `city` не загружено
(ключ `city` отсутствует). Загружается только `schedules` — из них считается `workingHours`.

```json
{
  "id": 1,
  "name": "Партнер",
  "workingHours": {
    "openingHour": 10,
    "openingMinute": 0,
    "closingHour": 22,
    "closingMinute": 0
  },
  "isActive": true,
  "type": "shop",
  "image": { "id": 1, "path": "https://example.com/store.jpg" },
  "rating": 4.8,
  "isB2b": false,
  "isPickupEnabled": true,
  "pickupPaymentMethods": ["card"],
  "address": "г. Москва, ул. Ленина, 1",
  "description": null,
  "latitude": 55.75,
  "longitude": 37.61,
  "minPickupOrderAmount": 0,
  "customCheckboxes": {}
}
```

- `workingHours` может быть null — если магазин не работает 24/7 и на текущий день недели нет
  расписания либо не задано время открытия.
- `image`, `rating`, `description`, `latitude`, `longitude` могут быть null.
- `minOrderAmount` в поисковом ответе отсутствует: это поле появляется только там, где ресурс
  собирается с информацией о доставке или самовывозе.

### Product (`ProductResource`)

Полная схема полей — в `04_catalog.md` § 4.3 или в Scramble doc (`/docs/api`), компонент
`ProductResource`. В поиске и рекомендациях товары загружаются с отношениями `images`,
`activePromotions` и счётчиками добавок, поэтому набор полей такой:

```json
{
  "id": 101,
  "sku": "BORSCH-001",
  "title": "Борщ, 300 г",
  "description": null,
  "price": 220,
  "priceOld": 280,
  "discountPercent": 21,
  "currency": "RUB",
  "images": [{ "id": 1, "path": "https://example.com/borsch.jpg" }],
  "isFavorite": false,
  "servedWarm": false,
  "composition": null,
  "additionalInfo": null,
  "proteins": null,
  "fats": null,
  "carbohydrates": null,
  "calories": null,
  "weight": null,
  "attributes": [],
  "rating": 4.5,
  "reviewsCount": 0,
  "supplements": [],
  "hasSupplements": false,
  "hasRequiredSupplements": false,
  "quantityStep": 1,
  "measurementUnit": "piece",
  "measurementUnitLabel": "шт",
  "stockQuantity": 50,
  "isConfigurable": false,
  "customCheckboxes": {}
}
```

Особенности набора полей именно в поисковых ответах:

- `attributes` и `supplements` — всегда пустые массивы: отношения не загружаются;
- `hasSupplements` и `hasRequiredSupplements` присутствуют (счётчики загружены);
- `brand`, `category`, `categories`, `primary_category`, `priceRange`, `configurableAttributes`,
  `variants`, `groupQuantity`, `deliveryDate`, `pickupDate` — отсутствуют (соответствующие
  отношения/атрибуты не загружены);
- `defaultVariantId` и `selectedVariantId` присутствуют только у configurable-товаров
  (`isConfigurable=true`); `selectedVariantId` в поиске всегда null;
- `videoUrl` — только при включённом модуле `video` и заданном видео у товара;
- `isPreorder` — только при включённом модуле `preorders`;
- `availableFrom` — только при включённом модуле `product_ready_time`, может быть null;
- `priceOld`, `discountPercent`, `rating`, `description`, `composition`, `additionalInfo`,
  `weight`, КБЖУ-поля могут быть null.

---

## API Endpoints

## 14. SearchRepository

### `searchProducts`
Поиск товаров по запросу с группировкой по магазинам.

**Endpoint:** `GET /api/v1/search/products` · `[OPTIONAL AUTH]` · throttle 100/min.

| Параметр | Тип | Обязательный | Default | Описание |
|----------|-----|--------------|---------|----------|
| query | string | ✅ | — | Поисковый запрос, 2–255 символов (обрезается по краям) |
| page | int | ❌ | 1 | Номер страницы (пагинация по магазинам), min 1 |
| perPage | int | ❌ | 10 | Кол-во магазинов на странице, 1–50 (>50 → 422) |
| productsPerStore | int | ❌ | 10 | Макс. кол-во товаров на магазин, 1–50 (>50 → 422) |
| onlyDiscounted | bool | ❌ | — | Только товары со скидкой. Snake-алиас: `only_discounted` |
| sugarFree | bool | ❌ | — | Только товары без сахара. Snake-алиас: `sugar_free` |
| brandId | int | ❌ | — | Фильтр по бренду, min 1. Snake-алиас: `brand_id` |
| categoryId | int | ❌ | — | Фильтр по категории, min 1. Snake-алиас: `category_id` |
| categoriesLimit | int | ❌ | 5 | Размер секции `categories`; `0` отключает секцию. Snake-алиас: `categories_limit` |
| storeId | int | ❌ | — | Сетевой каталог: магазин для цены/остатка **и** для отбора доступных магазинов (в т.ч. секции `categories`); активный адрес при этом не учитывается. Без него — магазин активного адреса. В стандартном и marketplace-режиме игнорируется. min 1, иное → 422 |

Snake_case-имена принимаются как алиасы camelCase (устаревшие, оставлены для совместимости):
если пришли оба варианта, приоритет у camelCase.

`categoriesLimit` валидируется как целое ≥ 0; значения больше 20 не дают 422, а усекаются до 20
(`meilisearch.search.max_categories_limit`).

При передаче Bearer-токена поиск фильтруется по магазинам, доступным для активного адреса
пользователя (`AvailableStoreService`). Если у пользователя нет активного адреса или токен не
передан, отбор идёт по всем активным магазинам с учётом типа B2B/B2C.

**Ответ**: `{hasMore: bool, data: StoreSearchResultResource[], total: int, categories: CategoryResource[]}`

- `total` — количество магазинов, попавших в выдачу целиком (не количество товаров). Считается по
  магазинам, прошедшим отбор по доступности; магазин, отсеянный на финальной загрузке из БД
  (стал неактивным либо не подходит по типу B2B/B2C), в `data` не попадает, но остаётся учтённым
  в `total`;
- `hasMore` — есть ли следующая страница магазинов;
- `data[].totalInStore` — количество валидных найденных товаров в магазине (может превышать
  длину `data[].products`, ограниченного `productsPerStore`);
- `categories` — секция категорий, не пагинируется и приходит одинаковой на каждой странице.

Пример (в объектах товара показана часть полей, полный набор — в разделе «Модели данных»):

```json
{
  "hasMore": true,
  "total": 5,
  "data": [
    {
      "store": {
        "id": 1,
        "name": "Партнер",
        "workingHours": {
          "openingHour": 10,
          "openingMinute": 0,
          "closingHour": 22,
          "closingMinute": 0
        },
        "isActive": true,
        "type": "shop",
        "image": { "id": 1, "path": "https://example.com/store.jpg" },
        "rating": 4.8,
        "isB2b": false,
        "isPickupEnabled": true,
        "pickupPaymentMethods": ["card"],
        "address": "г. Москва, ул. Ленина, 1",
        "description": null,
        "latitude": 55.75,
        "longitude": 37.61,
        "minPickupOrderAmount": 0,
        "customCheckboxes": {}
      },
      "products": [
        {
          "id": 101,
          "sku": "BORSCH-001",
          "title": "Борщ, 300 г",
          "price": 220,
          "priceOld": null,
          "discountPercent": null,
          "currency": "RUB",
          "images": [{ "id": 1, "path": "https://example.com/borsch.jpg" }],
          "isFavorite": false,
          "measurementUnit": "piece",
          "measurementUnitLabel": "шт",
          "quantityStep": 1,
          "stockQuantity": 50,
          "rating": 4.5
        }
      ],
      "totalInStore": 2
    }
  ],
  "categories": [
    {
      "id": 12,
      "name": "Молоко",
      "slug": "moloko",
      "position": 3,
      "image": { "id": 12, "path": "https://..." },
      "backgroundImage": null,
      "tagText": null,
      "tagColor": null,
      "parent": {
        "id": 5,
        "name": "Молочные продукты",
        "slug": "molochnye-produkty",
        "position": 1,
        "image": null,
        "backgroundImage": null,
        "tagText": null,
        "tagColor": null,
        "children": null,
        "customCheckboxes": {}
      },
      "children": null,
      "customCheckboxes": {}
    }
  ]
}
```

**Секция `categories`** (METRO-семантика, задача MID-171): категории, в названии которых
встречаются все слова запроса (регистронезависимо, сравнение по mb-функциям, кириллица
поддержана). В отбор попадают только активные категории, привязанные активной связью
(`category_store.is_active = true`) хотя бы к одному доступному магазину — адресная и B2B-логика
та же, что у товаров. Сортировка: сначала категории, название которых начинается с запроса,
затем по алфавиту. Секция заполняется независимо от товарной выдачи: она возвращается и при нуле
найденных товаров, и при недоступном Meilisearch. У категории подгружен `parent`.

Поля `CategoryResource` в этой секции: `id`, `name`, `slug`, `position`, `image` (может быть
null), `backgroundImage` (может быть null), `tagText` (всегда null — счётчики товаров и
подкатегорий не загружены), `tagColor` (всегда null), `children` (всегда null),
`customCheckboxes` (объект, по умолчанию пустой), `parent` (присутствует только у категорий
с `parent_id`; вложенный объект того же формата). Ключи `isLeaf` и `products` в этой секции
отсутствуют.

---

### `getSearchSuggestions`
Подсказки для автодополнения поиска.

**Endpoint:** `GET /api/v1/search/suggestions` · throttle 100/min. Bearer-токен опционален
(middleware нет, пользователь читается через guard `sanctum`). Доступные магазины: если передан
`storeId` — только он (активный адрес не учитывается, как у `/search/products`); иначе при
Bearer-токене — магазины активного адреса, без него — активные магазины с учётом B2B/B2C.
Фронту следует передавать `storeId` выбранного магазина: товарный поиск идёт по нему, и если
сохранённый адрес пользователя этот магазин не покрывает, без `storeId` секция `categories`
пришла бы пустой при непустых товарах.

| Параметр | Тип | Обязательный | Default | Описание |
|----------|-----|--------------|---------|----------|
| query | string | ✅ | — | Начало поискового запроса, 1–255 символов |
| limit | int | ❌ | 10 | Макс. кол-во подсказок, 1–20 (вне диапазона → 422) |
| structured | bool | ❌ | false | Допустимые значения: `0`, `1`, `true`, `false` (иное → 422) |
| storeId | int | ❌ | — | ID выбранного магазина (min 1, иное → 422): подсказки и категории резолвятся по нему, адрес не учитывается |

Минимальная длина запроса здесь 1 символ, а не 2, как у `/search/products`.

**Ответ (без `structured` или `structured=0|false`):** `string[]` — уникальные названия найденных
товаров, не более `limit` штук.

```json
["Борщ", "Борщ с мясом", "Борщ украинский"]
```

**Ответ (`structured=1|true`):** объект с тремя секциями.

```json
{
  "categories": [{ "id": 1, "name": "Молочные", "slug": "molochnye" }],
  "brands": [{ "id": 5, "title": "Простоквашино", "logoImage": { "url": "https://.../logo.png" } }],
  "products": ["Молоко 3.2%", "Молоко безлактозное"]
}
```

- `categories` — сначала категории, чьё название матчится со всеми словами запроса
  (METRO-семантика, MID-171, тот же алгоритм и те же ограничения по доступным магазинам, что
  в `/search/products`), затем добивка активными категориями найденных товаров (из поля
  `category_ids` документов Meilisearch); дедуп по id, суммарно не более `limit`. Формат
  элемента — `{id, name, slug}`; `slug` — для перехода на страницу каталога по категории;
- `brands` — активные бренды найденных товаров, формат `{id, title, logoImage}`, где
  `logoImage` — объект `{url}` (полный URL логотипа) либо null, если логотип не задан;
- `products` — массив строк-названий (текстовые подсказки), не карточки товаров.

Если доступных магазинов нет, подсказки пустые. При ошибке Meilisearch ответ — пустой массив
(плоский режим) либо объект с тремя пустыми секциями (`structured=true`), HTTP 200. Результаты
подсказок не кешируются.

---

### `getSearchRecommendations`
Подборка товаров для экрана поиска: начальное состояние (пустое поле ввода) и zero-results.
Оба состояния используют один эндпоинт.

**Endpoint:** `GET /api/v1/search/recommendations` · `[OPTIONAL AUTH]` · throttle 100/min.

| Параметр | Тип | Обязательный | Default | Описание |
|----------|-----|--------------|---------|----------|
| limit | int | ❌ | 20 | Макс. количество товаров, 1–50 (вне диапазона или нечисловое → 422) |
| storeId | int | ❌ | — | Сетевой каталог: магазин для цены/остатка и доступности товаров; активный адрес при этом не учитывается. Без него — магазин активного адреса. min 1, иное → 422 |

При передаче Bearer-токена с активным адресом подборка фильтруется по магазинам, доступным для
этого адреса (`AvailableStoreService`). Без токена или без активного адреса — по всем активным
магазинам с учётом типа B2B/B2C. Если доступных магазинов нет, ответ `{"data": [], "total": 0}`.
В сетевом каталоге переданный `storeId` имеет приоритет: подборка считается по его офферам даже
если активный адрес не покрывает ни один магазин.

**Логика:**
1. Источник — флаг `products.on_search_page` (управляется в админке, приезжает в storefront через
   RabbitMQ `product.updated`).
2. Сначала выбираются помеченные товары (`on_search_page=true`), свежие сверху
   (`orderByDesc('updated_at')`).
3. Если помеченных меньше чем `limit` — подборка добивается свежими активными товарами из тех же
   доступных магазинов (без флага), исключая уже выбранные.
4. Базовые фильтры: `is_active=true`, `parent_id IS NULL` (вариации configurable-товаров не
   показываются), остаток покрывает шаг покупки (или у товара `stock_mode=ignore`, или у магазина
   `ignore_stock=true`), `price > 0`.

**Ответ**: `{data: ProductResource[], total: int}`, где `total` — количество товаров в текущем
ответе (не общее число доступных). Результаты не кешируются.

```json
{
  "data": [
    {
      "id": 101,
      "sku": "BORSCH-001",
      "title": "Борщ, 300 г",
      "price": 220,
      "priceOld": null,
      "images": [{ "id": 1, "path": "https://example.com/borsch.jpg" }],
      "isFavorite": false,
      "measurementUnit": "piece",
      "stockQuantity": 50
    }
  ],
  "total": 20
}
```

---

### `getSearchHistory` [AUTH] (❌ не реализовано)
Получить историю поиска пользователя. Маршрута в `routes/api.php` нет.

| Параметр | Тип | Обязательный | Описание |
|----------|-----|--------------|----------|
| limit | int | ❌ | Макс. кол-во записей (default: 10) |

**Ответ**: `String[]`

```json
["Борщ", "Молоко", "Хлеб"]
```

---

### `clearSearchHistory` (❌ не реализовано)
Очистить историю поиска. Маршрута в `routes/api.php` нет.

**Ответ**: `void`

---

## Примечания

1. **Минимальная длина запроса**: 2 символа для `/search/products` и
   `/search/stores/{storeId}/products`, 1 символ для `/search/suggestions`. Максимум везде —
   255 символов.
2. **Поиск выполняется по** полям индекса `title` и `sku` (`config/meilisearch.php`,
   `searchableAttributes`). Стратегия матчинга — `all`: в документе должны найтись все слова
   запроса. Включена опечаточная устойчивость: одна опечатка допускается со слова длиной от 4
   символов, две — от 8.
3. **Что попадает в индекс**: только активные товары без `parent_id`. Вариации
   configurable-товаров не индексируются; у родителя в документ пишутся агрегированные цена
   (минимальная по активным детям) и остаток (сумма по детям).
4. **Базовые фильтры видимости** (одинаковы для всех поисковых запросов к Meilisearch):
   `is_active = true`, `price > 0`; при выключенном модуле configurable-товаров добавляется
   `is_configurable = false`; при выключенном модуле предзаказов — требование наличия
   (`in_stock = true`, либо товар configurable, либо магазин в режиме «не учитывать остатки»).
   Список магазинов с `ignore_stock` кешируется на `MEILISEARCH_CACHE_TTL` секунд (по умолчанию 30)
   и сбрасывается при обновлении магазина.
5. **Остаток проверяется по БД, а не по индексу**: все хиты Meilisearch проходят повторную
   проверку (`is_active`, наличие с учётом `stock_mode=ignore` и `stores.ignore_stock`,
   `price > 0`). Счётчики `total` и `totalInStore` считаются уже по прошедшим проверку товарам,
   поэтому не расходятся со списком. Товар, пропавший из БД или ушедший в ноль после индексации,
   в выдачу и в счётчики не попадает.
6. **Сортировка результатов**: магазины — по количеству валидных найденных товаров (desc);
   внутри магазина порядок товаров — как выдал Meilisearch (релевантность).
7. **Пагинация**: в `/search/products` — по магазинам; в `/search/stores/{storeId}/products` —
   по товарам.
8. **`productsPerStore`** ограничивает количество товаров на магазин в ответе; полное количество
   найденного в магазине — в `totalInStore`.
9. **Кеширование `/search/products`**: результат кешируется на `MEILISEARCH_CACHE_TTL` секунд
   (по умолчанию 30). Ключ кеша складывается из запроса, `page`, `perPage`, `productsPerStore`,
   признака B2B, фильтров и `categoriesLimit`; конкретный адрес и id пользователя в ключ не
   входят, поэтому в пределах TTL запросы с одинаковыми параметрами получают один и тот же
   ответ. `/search/stores/{storeId}/products`, `/search/suggestions` и `/search/recommendations`
   не кешируются.
10. **Стоп-лист** (`stop_list_hidden`) в поиске не участвует: поле удалено и из документа
    Meilisearch, и из фильтров.
11. **Поиск по штрихкоду** — отдельный эндпоинт вне группы `search/*`:
    `GET /api/v1/products/search/barcode/{barcode}` (описан в спеке каталога).

---

## Поиск внутри одного магазина

### `searchProductsInStore`

**Endpoint:** `GET /api/v1/search/stores/{storeId}/products` · throttle 100/min.
**`storeId` — path parameter**, не query. Магазин ищется по id через route-binding: несуществующий
или удалённый магазин → HTTP 404. Bearer-токен эндпоинтом не учитывается: отбор идёт по указанному
магазину, без фильтрации по доступности адреса и по типу B2B/B2C.

| Параметр | Где | Тип | Обязательный | Default | Описание |
|----------|-----|-----|--------------|---------|----------|
| storeId | **path** | int | ✅ | — | ID магазина |
| query | query | string | ✅ | — | Поисковый запрос, 2–255 символов |
| page | query | int | ❌ | 1 | Номер страницы, min 1 |
| perPage | query | int | ❌ | 20 | Кол-во товаров на странице, 1–100 (>100 → 422) |
| onlyDiscounted | query | bool | ❌ | — | Только со скидкой. Snake-алиас: `only_discounted` |
| sugarFree | query | bool | ❌ | — | Только без сахара. Snake-алиас: `sugar_free` |
| brandId | query | int | ❌ | — | Фильтр по бренду, min 1. Snake-алиас: `brand_id` |
| categoryId | query | int | ❌ | — | Фильтр по категории, min 1. Snake-алиас: `category_id` |

**Ответ**: `{hasMore: bool, data: ProductResource[], total: int}`, где `total` — общее количество
валидных найденных товаров в магазине, `hasMore` — есть ли следующая страница товаров.
Секции `categories` у этого эндпоинта нет. При ошибке Meilisearch — `{"hasMore": false,
"data": [], "total": 0}` с HTTP 200.

---

## UX Flow (контекст Figma, не часть контракта)

1. Пользователь вводит запрос в поле поиска
2. После 2+ символов отправляется запрос `searchProducts`
3. Отображается кол-во результатов: "20 результатов"
4. Под каждым магазином — горизонтальный скролл товаров (до `productsPerStore` шт.)
5. При клике на товар — переход на страницу товара
6. При клике на магазин — переход в магазин с активным поисковым фильтром (или `searchProductsInStore`)
