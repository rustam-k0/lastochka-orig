# ТЗ: Магазины

## 1. Цель

Получение списка магазинов, детальной информации о магазине, секций меню для магазинов-ресторанов и справочника городов.

## 2. Контекст

- **Модуль:** Store
- **Триггер:** Клиент открывает главный экран, выбирает магазин или город
- **Источник данных:** админ-панель (события RabbitMQ), внешний сервис слотов (`slots-api`) для данных доставки по адресу
- **Связи:** категории и товары привязаны к магазину через `store_id`; магазин связан с городом через `city_id`

## 3. Данные

### 3.1 Таблица stores

```
stores
├── id: bigint PK
├── code: string(50) NOT NULL UNIQUE         // shop_code
├── source: string(50) NOT NULL              // bagisto, retailcrm
├── name: string(200) NOT NULL
├── city_id: bigint FK → cities.id NULL      // (новое) null on delete
├── image_path: string(500) NULL             // URL логотипа
├── min_order_amount: int NULL               // Минимальная сумма заказа
├── delivery_price: int NULL                 // Стоимость доставки
├── free_delivery_from: int NULL             // Бесплатная доставка от
├── rating: decimal(2,1) NULL                // Рейтинг 0.0-5.0
├── reviews_count: unsigned int DEFAULT 0
├── latitude: decimal(10,7) NULL
├── longitude: decimal(10,7) NULL
├── address: string(500) NULL                // (новое) адрес магазина строкой
├── description: text NULL                   // (новое) описание магазина
├── slot_duration: int NULL                  // Размер слота в минутах
├── is_open_24_7: boolean DEFAULT false
├── is_active: boolean DEFAULT true
├── is_b2b: boolean DEFAULT false            // (новое) индексируется
├── type: string DEFAULT 'shop'              // (новое) shop | restaurant
├── ignore_stock: boolean DEFAULT false      // (новое) магазин не учитывает остатки
├── is_pickup_enabled: boolean DEFAULT false // (новое)
├── min_pickup_order_amount: unsigned int NULL       // (новое)
├── pickup_payment_methods: json NULL                // (новое)
├── pickup_auto_cancel_enabled: boolean DEFAULT true // (новое)
├── pickup_wait_perishable_hours: unsigned tinyint DEFAULT 1  // (новое)
├── pickup_wait_regular_hours: unsigned tinyint DEFAULT 3     // (новое)
├── pickup_grace_period_hours: unsigned tinyint DEFAULT 1     // (новое)
├── heavy_order_threshold: unsigned int NULL // (новое) порог тяжёлого заказа, граммы
├── assembly_price: unsigned int NULL        // (новое) стоимость сборки
├── is_bonus_enabled: boolean DEFAULT false  // (новое)
├── bonus_earn_percent: unsigned tinyint DEFAULT 0   // (новое)
├── bonus_spend_percent: unsigned tinyint DEFAULT 0  // (новое)
├── custom_checkboxes: json NULL             // (новое) map field => bool из admin_back
├── created_at: timestamp
├── updated_at: timestamp
└── deleted_at: timestamp NULL
```

**Индексы:**
- `code` уникальный
- `is_active`
- `is_b2b`

Поля `heavy_order_threshold`, `assembly_price`, бонусные и pickup-настройки участвуют в контрактах других разделов (см. `07_delivery.md`, `19_bonuses.md`, `27_pickup.md`) и в ответе StoreResource не возвращаются, кроме перечисленных в 4.6.

### 3.2 Таблица store_schedules

```
store_schedules
├── id: bigint PK
├── store_id: bigint FK → stores.id (cascade on delete)
├── day_of_week: tinyint NOT NULL            // 1=Mon, 7=Sun
├── opens_at: time NULL                      // NULL = выходной
├── closes_at: time NULL
└── UNIQUE (store_id, day_of_week)
```

### 3.3 Таблица cities (новое)

```
cities
├── id: bigint PK
├── name: string(100) NOT NULL
├── latitude: decimal(10,7) NULL
├── longitude: decimal(10,7) NULL
├── is_active: boolean DEFAULT true
├── is_default: boolean DEFAULT false
├── created_at / updated_at: timestamp
└── deleted_at: timestamp NULL
```

### 3.4 Вычисляемое поле workingHours

- `is_open_24_7 = true`: возвращается `{openingHour: 0, openingMinute: 0, closingHour: 24, closingMinute: 0}`.
- Иначе берётся расписание на текущий день недели (`day_of_week` в формате ISO, 1 = понедельник).
- Если записи на сегодня нет или `opens_at` пуст, поле равно `null` (выходной).

## 4. Логика

### 4.1 getStores

**Endpoint:** `GET /api/v1/stores`

**Middleware:** отсутствует, эндпоинт публичный. Авторизованный пользователь определяется через guard `sanctum`, если токен передан.

**Query параметры:**

| Параметр | Тип | Валидация | Поведение |
|---|---|---|---|
| `page` | int | `sometimes, integer, min:1` | по умолчанию 1 |
| `perPage` | int | `sometimes, integer, min:1` | по умолчанию 20, значения выше 100 усекаются до 100 |
| `addressId` | int | `sometimes, integer` | включает режим «доступные магазины для адреса» |
| `shippingMethod` | string | не валидируется | значение `pickup` включает режим самовывоза, любое другое значение игнорируется |

Нарушение валидации `page`, `perPage`, `addressId` даёт 422.

**Порядок выбора режима:**
1. `shippingMethod = pickup`: режим самовывоза, `addressId` не учитывается.
2. Запрос авторизован и `addressId > 0`: режим доступных магазинов для адреса.
3. Иначе: общий список магазинов.

Запрос с `addressId` **без авторизации** обрабатывается по третьему пункту: параметр игнорируется и возвращается общий список магазинов.

**Общий список:**
1. Отбираются активные магазины (`is_active = true`) с учётом B2B-фильтра (см. 4.7).
2. Сортировка по `name`, при включённом модуле `b2b` для B2B-пользователя перед ней применяется `is_b2b DESC`.
3. Пагинация средствами БД.
4. Каждому магазину проставляется `deliveryInfo` из полей магазина: `deliveryPrice = delivery_price ?? 0`, `minOrderAmount = min_order_amount ?? 0`, `freeDeliveryFrom = null`.

**Режим самовывоза (`shippingMethod=pickup`):**
1. К фильтрам добавляется `is_pickup_enabled = true`.
2. Блок `deliveryInfo` не формируется.
3. К объекту магазина добавляется поле `minOrderAmount`, равное `min_pickup_order_amount ?? min_order_amount ?? 0`.

**Режим по адресу (`addressId`, требуется авторизация):**
1. Адрес ищется по id; если не найден, ответ 404 `address_not_found`.
2. Если адрес принадлежит другому пользователю, ответ 403 `forbidden`.
3. Если у адреса нет координат либо обе координаты равны нулю, ответ 422 `address_missing_coordinates`.
4. Список доступных кодов магазинов берётся из кеша, при его отсутствии запрашивается у `slots-api` (см. `TZ-available-stores-by-address.md`).
5. Из БД выбираются активные магазины с этими кодами, с учётом B2B-фильтра, сортировка по `name`.
6. Пагинация выполняется в памяти по полученной коллекции.
7. `deliveryInfo` заполняется данными `slots-api` для пары адрес + магазин.

Если `slots-api` недоступен или вернул ошибку, список доступных кодов пуст и ответ содержит пустой `data` с `total = 0`.

**Ответ 200:**
```json
{
  "hasMore": true,
  "data": [
    {
      "id": 1,
      "name": "Магазин №1",
      "workingHours": {
        "openingHour": 9,
        "openingMinute": 0,
        "closingHour": 21,
        "closingMinute": 0
      },
      "isActive": true,
      "type": "shop",
      "image": {"id": 1, "path": "https://cdn.example.com/store1.jpg"},
      "rating": 4.5,
      "isB2b": false,
      "city": {"id": 1, "name": "Москва"},
      "isPickupEnabled": true,
      "pickupPaymentMethods": ["cash", "card"],
      "address": "Москва, Ленина, 1",
      "description": "Продукты, своя кухня, бытовая химия",
      "latitude": 55.7558,
      "longitude": 37.6173,
      "minPickupOrderAmount": 0,
      "customCheckboxes": {},
      "deliveryInfo": {
        "deliveryPrice": 200,
        "minOrderAmount": 500,
        "freeDeliveryFrom": 3000
      }
    }
  ],
  "total": 10
}
```

`hasMore` показывает наличие следующей страницы, `total` содержит общее количество магазинов в выборке. Элементы `data` не обёрнуты в дополнительный ключ.

**Ошибки:** 403 `forbidden`, 404 `address_not_found`, 422 `address_missing_coordinates`, 422 при нарушении валидации query-параметров. Тело ошибок режима по адресу: `{"error": "<код>", "message": "<текст>"}`.

### 4.2 getStore

**Endpoint:** `GET /api/v1/stores/{storeId}`

Параметр роута ограничен regex `[0-9]+`, принимается только числовой id.

**Query:** `addressId` (тот же контракт, что в 4.1). Без авторизации параметр игнорируется.

**Логика без `addressId`:**
1. Магазин ищется среди активных с учётом B2B-фильтра.
2. Если не найден, неактивен или скрыт B2B-фильтром, ответ 404.
3. `deliveryInfo` заполняется из полей магазина (`freeDeliveryFrom` при этом всегда `null`).

**Логика с `addressId`:**
1. Проверки адреса те же, что в 4.1 (404 `address_not_found`, 403 `forbidden`, 422 `address_missing_coordinates`).
2. Магазин ищется среди активных с учётом B2B-фильтра; если не найден, ответ 404 `store_not_found`.
3. Если код магазина отсутствует среди доступных для адреса, ответ 404 `store_not_available`.
4. `deliveryInfo` заполняется данными `slots-api`.

**Ответ 200:** `{"data": {…StoreResource…}}` (одиночный ресурс обёрнут в `data`).

**Ошибки:**
- 404 магазин не найден, неактивен или недоступен для адреса (`store_not_found`, `store_not_available`)
- 404 `address_not_found`
- 403 `forbidden`
- 422 `address_missing_coordinates`

### 4.3 getMenuSections (новое)

**Endpoint:** `GET /api/v1/stores/{storeId}/menu-sections`

Параметр роута ограничен regex `[0-9]+`. Эндпоинт публичный, пагинации нет.

**Логика:**
1. Магазин ищется среди активных. Если магазин не найден, неактивен или его `type` не равен `restaurant`, ответ 404.
2. Собираются секции двух типов:
   - товарные группы магазина: активные, с признаком `show_on_store_page`, сортировка по `position`;
   - категории магазина: корневые (`parent_id` пуст), активные и с активной привязкой к магазину, сортировка по позиции в магазине (`category_store.position`).
3. Обе коллекции объединяются и сортируются по `position` сквозным образом.
4. Полю `sortOrder` присваиваются номера 1..N в получившемся порядке; порядок вывода определяет сервер.
5. Для каждой секции подбираются товары: для группы — товары группы, для категории — товары магазина в этой категории (доступные к покупке, в порядке сортировки каталога). Количество товаров в одной секции ограничено 200.

**Ответ 200:**
```json
{
  "data": [
    {
      "type": "product_group",
      "id": 12,
      "title": "Пицца",
      "image": {"id": 12, "path": "https://cdn.example.com/pizza.jpg"},
      "sortOrder": 1,
      "products": []
    },
    {
      "type": "category",
      "id": 5,
      "title": "Салаты",
      "image": null,
      "sortOrder": 2,
      "products": []
    }
  ]
}
```

- `type`: `product_group` или `category`
- `title`: заголовок группы (`title`) либо название категории (`name`)
- `image`: объект `{id, path}` или `null`
- `products`: массив товаров в формате ProductResource (см. `04_catalog.md`)

**Ошибки:** 404 (магазин не найден, неактивен либо не является рестораном).

### 4.4 getCities (новое)

**Endpoint:** `GET /api/v1/cities`

Публичный эндпоинт, авторизация не требуется, пагинации нет.

**Логика:**
1. Отбираются города с `is_active = true`.
2. Сортировка: `is_default DESC`, затем `name`.

**Ответ 200:**
```json
{
  "data": [
    {"id": 1, "name": "Москва", "latitude": 55.7558, "longitude": 37.6173, "isDefault": true},
    {"id": 2, "name": "Казань", "latitude": null, "longitude": null, "isDefault": false}
  ]
}
```

`latitude` и `longitude` могут быть null (значение `0` также отдаётся как `null`), `isDefault` булево.

### 4.5 Вычисление workingHours

```php
public function getWorkingHoursAttribute(): ?WorkingHours
{
    if ($this->is_open_24_7) {
        return new WorkingHours(0, 0, 24, 0);
    }

    $today = now()->dayOfWeekIso; // 1=Mon, 7=Sun
    $schedule = $this->schedules->firstWhere('day_of_week', $today);

    if (! $schedule || ! $schedule->opens_at) {
        return null; // Выходной
    }

    return new WorkingHours(
        openingHour: (int) $schedule->opens_at->format('H'),
        openingMinute: (int) $schedule->opens_at->format('i'),
        closingHour: (int) $schedule->closes_at->format('H'),
        closingMinute: (int) $schedule->closes_at->format('i'),
    );
}
```

### 4.6 Поля StoreResource

| Поле | Тип | Примечание |
|---|---|---|
| `id` | int | |
| `name` | string | |
| `workingHours` | object, может быть null | см. 3.4 и 4.5 |
| `isActive` | bool | |
| `type` | string | (новое) `shop` по умолчанию, `restaurant` для магазинов-ресторанов |
| `image` | object `{id, path}`, может быть null | `null`, если `image_path` пуст; `path` всегда полный URL |
| `rating` | float, может быть null | `null`, если рейтинг не задан или равен 0; значение пересчитывается консьюмером `store_reviews` (см. `15_reviews.md`) |
| `isB2b` | bool | (новое) |
| `city` | object `{id, name}` | (новое) поле отсутствует в ответе, если у магазина не задан город |
| `isPickupEnabled` | bool | (новое) присутствует во всех режимах |
| `pickupPaymentMethods` | string[] | (новое) при пустом значении в БД возвращается `["card"]` |
| `address` | string, может быть null | (новое) |
| `description` | string, может быть null | (новое) |
| `latitude`, `longitude` | float, могут быть null | значение `0` отдаётся как `null` |
| `minPickupOrderAmount` | int | (новое) `min_pickup_order_amount ?? min_order_amount ?? 0` |
| `customCheckboxes` | object | (новое) пустой объект, если чекбоксы не заданы |
| `deliveryInfo` | object | присутствует, когда сервер приложил данные доставки (см. ниже) |
| `minOrderAmount` | int | (новое) только в режиме `shippingMethod=pickup`, равен `min_pickup_order_amount ?? min_order_amount ?? 0` |

Поля `code`, `source`, `reviews_count`, `slot_duration` и настройки самовывоза кроме перечисленных в ответе не возвращаются. Отдельного поля `minOrderAmount` в обычном режиме нет, минимум курьерской доставки лежит в `deliveryInfo.minOrderAmount`.

**Блок deliveryInfo:**
```json
{"deliveryPrice": 200, "minOrderAmount": 500, "freeDeliveryFrom": 3000}
```
- Без `addressId`: `deliveryPrice = delivery_price ?? 0`, `minOrderAmount = min_order_amount ?? 0`, `freeDeliveryFrom` всегда `null` (поле `free_delivery_from` магазина в этот режим не попадает).
- С `addressId`: значения приходят от `slots-api` для зоны конкретного адреса (`delivery_price`, `min_order_price`, `free_delivery_from`); `freeDeliveryFrom` равен `null`, если внешний сервис его не прислал.
- В режиме `shippingMethod=pickup` блок отсутствует.
- Блок также отсутствует, если для пары адрес + магазин у сервера нет данных доставки.

### 4.7 Фильтрация по типу пользователя (B2B)

Поведение зависит от модуля `b2b` в настройке `project_modules`:
- модуль выключен: фильтр не применяется, все пользователи и гости видят все активные магазины;
- модуль включён, пользователь с `is_b2b = true`: видит все магазины, B2B-магазины идут первыми;
- модуль включён, обычный пользователь или гость: видит только магазины с `is_b2b = false`.

Фильтр применяется во всех трёх режимах `GET /v1/stores` и в `GET /v1/stores/{storeId}`.

## 5. Нефункциональные требования

### Кеширование
- Ответы `GET /v1/stores` и `GET /v1/stores/{storeId}` не кешируются.
- Кешируется ответ `slots-api` по адресу: ключ `available_stores:address:{addressId}`, TTL из `config('services.slots_api.cache_ttl')` (переменная окружения `SLOTS_API_CACHE_TTL`, значение по умолчанию 3600 секунд).
- Кеш инвалидируется при изменении координат адреса и при его удалении (`AddressObserver`), а также методами `refreshCache`, `invalidateCache`, `invalidateAllAddressCaches` сервиса `AvailableStoreService`.

### Внешние вызовы
- `SlotsApiClient` обращается к `GET {SLOTS_API_BASE_URL}/api/v1/slots/shops?latitude=&longitude=`, connect timeout 5 секунд, timeout из `config('services.slots_api.timeout')` (по умолчанию 10 секунд), один повтор с задержкой 1000 мс для ответов 5xx и ошибок соединения.
- Ошибка внешнего сервиса логируется и приводит к пустому списку доступных магазинов, исключение наружу не пробрасывается.

### Производительность
- Магазины выбираются с eager load `schedules` и `city` во всех режимах.
- Секции меню ограничены 200 товарами на секцию.

## 6. Ожидаемый результат

### Код
- [x] Миграции: `create_stores_table`, `create_store_schedules_table`, `create_cities_table`, `add_city_id_to_stores_table`, `add_is_b2b_to_stores_table`, `add_pickup_fields_to_stores_table`, `add_type_to_stores_table`, `add_description_to_stores_table`, `add_ignore_stock_to_stores_table`, `add_bonus_fields_to_stores_table`, `add_heavy_order_threshold_to_stores_table`, `add_assembly_price_to_stores_table`, `add_custom_checkboxes_to_content_entities`
- [x] Модели `Store` (scopes `active`, `forUserType`, `pickupEnabled`; accessor `workingHours`), `StoreSchedule`, `City`
- [x] `StoreController`, `MenuSectionController`, `CityController`
- [x] `GetStoresRequest`
- [x] `StoreResource`, `WorkingHoursResource`, `MenuSectionResource`, `CityResource`, `ImageResource`
- [x] `AvailableStoreService`, `SlotsApiClient` (в тестах подменяется на `FakeSlotsApiClient`)
- [x] DTO `WorkingHours`, `MenuSectionData`

### Тесты
- `tests/Feature/Api/V1/Store/StoreTest.php`
- `tests/Feature/Api/V1/Store/AvailableStoreTest.php`
- `tests/Feature/Api/V1/Store/MenuSectionsTest.php`
- `tests/Feature/Api/V1/B2b/B2bStoreTest.php`
- `tests/Unit/Models/StoreForUserTypeScopeTest.php`, `tests/Unit/Services/Store/AvailableStoreServiceTest.php`

### Routes
```php
Route::prefix('v1')->group(function () {
    Route::get('brands', [BrandController::class, 'index']);
    Route::get('cities', [CityController::class, 'index']);

    Route::prefix('stores')->group(function () {
        Route::get('/', [StoreController::class, 'index']);
        Route::get('/{storeId}', [StoreController::class, 'show'])->where('storeId', '[0-9]+');
        Route::get('/{storeId}/menu-sections', [MenuSectionController::class, 'index'])->where('storeId', '[0-9]+');
    });
});
```

## 7. Бренды (BrandController)

### GET /api/v1/brands (публичный)

Возвращает активные бренды, отсортированные по `position`. При выключенном модуле `brands` в настройке `project_modules` возвращается пустой список `{"data": []}`.

**Response 200:**
```json
{
  "data": [
    {
      "id": 1,
      "title": "ACME",
      "link": "https://acme.example.com",
      "countryOrigin": "Италия",
      "countryProduction": "Россия",
      "rating": 4.6,
      "certificate": "https://cdn.example.com/certs/acme.pdf",
      "certificateName": "Сертификат соответствия",
      "logoImage": {"id": 1, "path": "https://cdn.example.com/brands/acme.png"},
      "customCheckboxes": {}
    }
  ]
}
```

Поля `link`, `countryOrigin`, `countryProduction`, `certificate`, `certificateName`, `logoImage` могут быть null. `rating` (новое) содержит средний рейтинг активных товаров бренда, округлённый до одного знака, и равен null при отсутствии оценок. `customCheckboxes` (новое) возвращается объектом, пустым при отсутствии значений.
