# ТЗ: Адреса пользователей

## 1. Цель

CRUD адресов доставки с поддержкой активного адреса, адресов-объектов (дополнительные данные о доставке на объект плюс фотографии) и адресов точек самовывоза СДЭК / Почты России.

## 2. Контекст

- **Модуль:** Address
- **Триггер:** Пользователь управляет адресами в профиле или при оформлении заказа
- **Зависимости:**
  - User (владелец адреса)
  - модуль `addresses.objects` в настройке `project_modules` (таблица `storefront_settings`) включает блок «объект» и работу с фотографиями
  - `AvailableStoreService` (кеш доступных магазинов инвалидируется по событиям модели Address)

## 3. Данные

### 3.1 Таблица addresses

```
addresses
├── id: bigint PK
├── user_id: bigint FK → users.id (cascade on delete)
├── title: string(50) NULL              // "Дом", "Работа"
├── country: string(100) NOT NULL
├── city: string(100) NOT NULL
├── street: string(200) NULL            // с 12.04.2026 nullable
├── house_number: string(20) NULL       // с 12.04.2026 nullable
├── entrance: string(10) NULL           // Подъезд
├── floor: string(10) NULL              // Этаж
├── apartment: string(20) NULL          // Квартира
├── intercom: string(20) NULL           // Домофон
├── comment: string(500) NULL           // Комментарий курьеру
├── latitude: decimal(10,7) NULL
├── longitude: decimal(10,7) NULL
├── full_address: string(500) NULL      // Полный адрес строкой, вычисляется сервером
├── additional: json NULL               // (новое) метаданные точки самовывоза
├── is_object: boolean DEFAULT false    // (новое) адрес-объект
├── object_data: json NULL              // (новое) данные объекта (cast ObjectAddressDataCast)
├── is_active: boolean DEFAULT false
├── created_at: timestamp
├── updated_at: timestamp
└── deleted_at: timestamp NULL
```

**Индексы:**
- `user_id` (FK)
- `user_id, is_active`
- `is_object`

### 3.2 Таблица address_photos (новое)

```
address_photos
├── id: bigint PK
├── address_id: bigint FK → addresses.id (cascade on delete)
├── disk: string(32) NOT NULL           // имя диска Storage
├── path: string NOT NULL               // путь файла на диске
├── mime: string(64) NOT NULL
├── size: unsigned int NOT NULL         // байты
├── sort_order: unsigned smallint DEFAULT 0
├── created_at / updated_at: timestamp
└── deleted_at: timestamp NULL
```

**Индексы:** `address_id, sort_order`.

Связь `Address::photos()` отдаёт фотографии, отсортированные по `sort_order`.

### 3.3 Инварианты

- Один активный адрес на пользователя. Инвариант поддерживается кодом (`CreateAddressAction`, `SetActiveAddressAction` снимают флаг со всех адресов пользователя в транзакции), а не ограничением БД.
- `full_address` формируется сервером и не принимается из запроса.

## 4. Логика

### 4.1 getAddresses [AUTH]

**Endpoint:** `GET /api/v1/addresses`

**Middleware:** `auth:sanctum`.

**Логика:**
1. Выбираются все адреса текущего пользователя (без удалённых) вместе со связью `photos`.
2. Сортировка: `is_active DESC`, затем `created_at DESC`.

**Ответ 200:**
```json
{
  "data": [
    {
      "id": 1,
      "userId": 1,
      "title": "Дом",
      "country": "Россия",
      "city": "Москва",
      "street": "Ленина",
      "houseNumber": "10",
      "entrance": "2",
      "floor": "5",
      "apartment": "42",
      "intercom": "42",
      "comment": "Домофон не работает",
      "latitude": 55.7558,
      "longitude": 37.6173,
      "fullAddress": "Россия, Москва, Ленина, д. 10, кв. 42",
      "isActive": true,
      "isObject": false,
      "objectData": null,
      "createdAt": "2026-01-15T10:30:00+00:00",
      "updatedAt": "2026-01-15T10:30:00+00:00"
    }
  ]
}
```

**Поля AddressResource:**

| Поле | Тип | Примечание |
|---|---|---|
| `id`, `userId` | int | |
| `title` | string, может быть null | |
| `country`, `city` | string | |
| `street`, `houseNumber` | string, могут быть null | |
| `entrance`, `floor`, `apartment`, `intercom`, `comment` | string, могут быть null | |
| `latitude`, `longitude` | float, могут быть null | значение `0` отдаётся как `null` |
| `fullAddress` | string, может быть null | вычисляется сервером |
| `isActive` | bool | |
| `isObject` | bool | (новое) |
| `objectData` | object, может быть null | (новое) см. 4.3 |
| `createdAt`, `updatedAt` | string ISO 8601 | |

Поле `additional` (метаданные точки самовывоза) в ответе не возвращается.

**Блок objectData** (новое) заполняется, только если одновременно: `is_object = true`, модуль `addresses.objects` включён, в `object_data` лежат данные объекта. Иначе `objectData = null`.

```json
{
  "name": "Многоэтажка",
  "contactName": "Иванов Иван Иванович",
  "contactPhone": "+79991234567",
  "company": "Звёздочка",
  "liftSize": "1,5 на 2",
  "hasLift": true,
  "needsPass": false,
  "canOversized": true,
  "workingHoursFrom": "10:00",
  "workingHoursTo": "20:00",
  "is247": false,
  "workingDays": ["mon", "tue", "wed", "thu", "fri"],
  "isDaily": false,
  "photos": [
    {"id": 7, "url": "https://.../address-photos/1/abc.jpg", "mime": "image/jpeg"}
  ]
}
```

`name` строка; `contactName`, `contactPhone`, `company`, `liftSize`, `workingHoursFrom`, `workingHoursTo` строки и могут быть null; `hasLift`, `needsPass`, `canOversized` булевы и могут быть null (null означает «неизвестно»); `is247`, `isDaily` булевы; `workingDays` массив строк; `photos` массив объектов (пустой, если фото нет).

**Ошибки:** 401, если запрос без валидного токена Sanctum.

### 4.2 getActiveAddress [AUTH]

**Endpoint:** `GET /api/v1/addresses/active`

**Логика:**
1. Выбирается адрес пользователя с `is_active = true` (со связью `photos`).
2. Если активного адреса нет, возвращается `null` в поле `data`.

**Ответ 200:**
```json
{"data": {"…AddressResource…": "…"}}
```
или
```json
{"data": null}
```

### 4.3 createAddress [AUTH]

**Endpoint:** `POST /api/v1/addresses`

**Входные данные:**
```json
{
  "title": "Дом",
  "country": "Россия",
  "city": "Москва",
  "street": "Ленина",
  "houseNumber": "10",
  "entrance": "2",
  "floor": "5",
  "apartment": "42",
  "intercom": "42",
  "comment": "Домофон не работает",
  "latitude": 55.7558,
  "longitude": 37.6173,
  "isObject": false,
  "objectData": null
}
```

**Валидация (`CreateAddressRequest`):**
- `title` nullable, string, max 50
- `country` required, string, max 100
- `city` required, string, max 100
- `street` nullable, string, max 200
- `houseNumber` nullable, string, max 20
- `entrance` nullable, string, max 10
- `floor` nullable, string, max 10
- `apartment` nullable, string, max 20
- `intercom` nullable, string, max 20
- `comment` nullable, string, max 500
- `latitude` **required**, numeric, between -90..90
- `longitude` **required**, numeric, between -180..180
- пара `latitude = 0` и `longitude = 0` отклоняется с 422; текст ошибки в полях `latitude` и `longitude`: `Координаты не могут быть нулевыми — выберите адрес на карте или повторите геокодинг`
- `isObject` (новое) nullable, boolean
- `objectData` (новое) array, обязателен при `isObject = true`
- `objectData.name` required при `isObject = true`, string, max 200
- `objectData.contactName` nullable, string, max 200
- `objectData.contactPhone` nullable, string, regex `^\+?\d{10,15}$`
- `objectData.company` nullable, string, max 200
- `objectData.liftSize` nullable, string, max 50
- `objectData.hasLift`, `objectData.needsPass`, `objectData.canOversized` nullable, boolean
- `objectData.workingHoursFrom`, `objectData.workingHoursTo` nullable, формат `H:i`; `workingHoursTo` должно быть позже `workingHoursFrom`
- `objectData.is247`, `objectData.isDaily` nullable, boolean
- `objectData.workingDays` nullable, array; элементы из набора `mon|tue|wed|thu|fri|sat|sun`

**Нормализация до валидации:**
- модуль `addresses.objects` выключен: сервер принудительно выставляет `isObject = false`, `objectData = null`, остальные поля блока не валидируются;
- `objectData.is247 = true`: сервер обнуляет `workingHoursFrom` и `workingHoursTo`;
- `objectData.isDaily = true`: сервер обнуляет `workingDays`.

**Логика (`CreateAddressAction`, одна транзакция):**
1. Со всех адресов пользователя снимается флаг `is_active`.
2. Создаётся адрес с `user_id` текущего пользователя.
3. `full_address` вычисляется как `"{country}, {city}, {street}, д. {houseNumber}"`, при заполненной квартире добавляется `", кв. {apartment}"`.
4. Новый адрес всегда сохраняется с `is_active = true` (не только первый адрес пользователя).

**HTTP:** `201 Created`, тело `{"data": {…AddressResource…}}`.

**Ошибки:** 401 без авторизации, 422 при нарушении валидации.

### 4.4 updateAddress [AUTH]

**Endpoint:** `PUT /api/v1/addresses/{address}`

**Валидация (`UpdateAddressRequest`):** те же поля, что и при создании, с отличиями:
- `country`, `city`, `street`, `houseNumber`, `latitude`, `longitude` помечены `sometimes|required`: поле можно не передавать, но переданное не может быть пустым;
- `latitude` и `longitude` передаются только вместе; передача одного из них даёт 422 «Широта и долгота должны передаваться вместе»;
- пара нулевых координат отклоняется так же, как при создании;
- остальные поля (`title`, `entrance`, `floor`, `apartment`, `intercom`, `comment`, блок `objectData`) nullable с теми же ограничениями длины.

**Логика (`UpdateAddressAction`):**
1. Политика `AddressPolicy::update` проверяет владельца.
2. Обновляются только переданные в запросе поля.
3. `full_address` пересчитывается по тем же правилам, что при создании.
4. При переключении `isObject` из `true` в `false` фотографии адреса удаляются (запись soft-delete плюс удаление файла с диска), `object_data` обнуляется.
5. Флаг `is_active` метод не изменяет.

**Ответ 200:** `{"data": {…AddressResource…}}`.

**Ошибки:**
- 401 без авторизации
- 403 адрес принадлежит другому пользователю
- 404 адрес не найден
- 422 нарушение валидации

### 4.5 deleteAddress [AUTH]

**Endpoint:** `DELETE /api/v1/addresses/{address}`

**Логика:**
1. Политика `AddressPolicy::delete` проверяет владельца.
2. Если адрес был активным, флаг `is_active` снимается перед удалением.
3. Выполняется soft delete адреса.
4. Другой адрес активным не назначается.
5. Кеш доступных магазинов для этого адреса инвалидируется (`AddressObserver::deleted`).

**Ответ:** `204 No Content`, тело пустое.

**Ошибки:** 401, 403 (чужой адрес), 404 (адрес не найден).

### 4.6 setActiveAddress [AUTH]

**Endpoint:** `PUT /api/v1/addresses/{address}/active`

**Логика (`SetActiveAddressAction`):**
1. Политика `AddressPolicy::setActive` проверяет владельца.
2. В транзакции с тремя попытками (конкурентные запросы одного пользователя дедлочатся на массовом UPDATE, SQLSTATE 40001, транзакция повторяется автоматически):
   - со всех адресов пользователя снимается `is_active`;
   - выбранному адресу проставляется `is_active = true`.

**Ответ 200:** `{"data": {…AddressResource…}}`.

**Ошибки:** 401, 403, 404.

### 4.7 uploadAddressPhotos [AUTH] (новое)

**Endpoint:** `POST /api/v1/addresses/{address}/photos`

**Middleware:** `auth:sanctum`, `address-objects-enabled`, `throttle:10,1` (10 запросов в минуту).

Middleware `address-objects-enabled` отдаёт 404, если модуль `addresses.objects` выключен.

**Формат:** `multipart/form-data`.

**Валидация (`UploadPhotoRequest`):**
- `photos` required, array, от 1 до 6 элементов
- `photos.*` файл, изображение, mime `jpg|jpeg|png`, размер до 5120 КБ (5 МБ)

**Логика:**
1. Политика `update` проверяет владельца адреса.
2. Если у адреса `is_object = false`, возвращается 422 «Адрес не является объектом».
3. Если суммарное количество фото (уже загруженные плюс новые) превышает 6, возвращается 422 «Превышен лимит 6 фото на адрес».
4. Файлы сохраняются на диск из `config('filesystems.address_photos_disk')` (переменная окружения `ADDRESS_PHOTOS_DISK`, значение по умолчанию `public`), в каталог `address-photos/{addressId}`.
5. `sort_order` каждой новой фотографии продолжает нумерацию от максимального существующего значения.

**Ответ:** `201 Created`, тело `{"data": {…AddressResource…}}` с актуальным списком `objectData.photos`.

**Ошибки:** 401, 403 (чужой адрес), 404 (адрес не найден либо модуль выключен), 422 (валидация, не-объект, превышение лимита), 429 (превышен лимит запросов).

### 4.8 deleteAddressPhoto [AUTH] (новое)

**Endpoint:** `DELETE /api/v1/addresses/{address}/photos/{photo}`

**Middleware:** `auth:sanctum`, `address-objects-enabled`, `throttle:30,1` (30 запросов в минуту).

**Логика:**
1. Политика `update` проверяет владельца адреса.
2. Если `photo.address_id` не совпадает с `{address}`, возвращается 404.
3. Запись фотографии удаляется мягко (soft delete), файл удаляется с диска физически.

**Ответ:** `204 No Content`.

**Ошибки:** 401, 403, 404 (адрес или фото не найдены, фото принадлежит другому адресу, модуль выключен), 429.

### 4.9 storePickupAddress [AUTH] (новое)

**Endpoint:** `POST /api/v1/customer/addresses/pickup`

**Middleware:** `auth:sanctum`. Ограничение частоты запросов не установлено.

Создаёт адрес пользователя на основе выбранной точки выдачи СДЭК или отделения Почты России.

**Валидация (`StorePickupAddressRequest`):**
- `provider` required, string, из набора `cdek|russian_post`
- `externalId` required, string, max 64 (код ПВЗ СДЭК или индекс отделения)
- `title` nullable, string, max 200
- `country` required, string, max 100
- `city` required, string, max 100
- `postalCode` nullable, string, max 20
- `street` required, string, max 300
- `lat` required, numeric, between -90..90
- `lng` required, numeric, between -180..180
- `phone` nullable, string, max 30
- `raw` nullable, array (сырые данные точки от провайдера)
- `region` в правилах валидации не описан, но читается контроллером; если не передан, подставляется значение `city`

**Логика:**
1. `title` адреса равен переданному `title`, при его отсутствии равен `city`.
2. `house_number` адреса заполняется значением `externalId`.
3. `latitude` и `longitude` заполняются из `lat` и `lng`.
4. `full_address` формируется как `"{title}, {city}"` (при пустом `city` только `title`), правило из 4.3 здесь не применяется.
5. Адрес создаётся с `is_active = false`, активный адрес пользователя не переключается.
6. В поле `additional` сохраняется структура: `kind` (всегда `pickup`), `provider`, `external_id`, `title`, `city`, `region`, `street`, `postal_code`, `lat`, `lng`, `phone`, `raw`.

**Ответ:** `201 Created`, тело `{"data": {…AddressResource…}}`. Содержимое `additional` в ответе не возвращается.

**Ошибки:** 401 без авторизации, 422 при нарушении валидации.

## 5. Нефункциональные требования

### Транзакции
- `createAddress`: снятие флага с прочих адресов и вставка нового выполняются в одной транзакции.
- `setActiveAddress`: транзакция с тремя попытками (повтор при дедлоке).

### Кеширование
- Кеш доступных магазинов (`available_stores:address:{id}`) инвалидируется `AddressObserver` при изменении `latitude`/`longitude` адреса и при его удалении.

### Бизнес-правила
- Лимит количества адресов на пользователя не установлен.
- Удаление активного адреса не назначает новый активный автоматически.
- Новый адрес, созданный через `POST /v1/addresses`, всегда становится активным; адрес точки самовывоза создаётся неактивным.
- `latitude` и `longitude` обязательны при создании адреса и используются для подбора доступных магазинов (`GET /v1/stores?addressId=`) и слотов доставки.

## 6. Связанные справочники

Справочник городов вынесен в отдельный публичный эндпоинт `GET /api/v1/cities`, он описан в [03_stores.md](03_stores.md).

## 7. Ожидаемый результат

### Код
- [x] Миграции: `create_addresses_table`, `make_street_nullable_in_addresses_table`, `add_object_fields_to_addresses_table`, `add_additional_to_addresses_table`, `create_address_photos_table`
- [x] Модели `Address` (scopes `forUser`, `active`; метод `buildFullAddress`) и `AddressPhoto`
- [x] `AddressController`, `AddressPhotoController`, `AddressPickupController`
- [x] `CreateAddressRequest`, `UpdateAddressRequest`, `UploadPhotoRequest`, `StorePickupAddressRequest`
- [x] `AddressResource`, DTO `ObjectAddressData`, cast `ObjectAddressDataCast`
- [x] Actions: `CreateAddressAction`, `UpdateAddressAction`, `SetActiveAddressAction`, `UploadAddressPhotoAction`, `DeleteAddressPhotoAction`
- [x] `AddressPolicy` (view, update, delete, setActive)
- [x] `AddressObserver` (инвалидация кеша магазинов)
- [x] Middleware `EnsureAddressObjectsEnabled` (алиас `address-objects-enabled`)

### Тесты
- `tests/Feature/Api/V1/Address/AddressTest.php`
- `tests/Feature/Api/V1/Address/AddressObjectTest.php`
- `tests/Feature/Api/V1/Address/AddressObjectModuleDisabledTest.php`
- `tests/Feature/Api/V1/Address/AddressPhotoUploadTest.php`
- `tests/Unit/DTOs/ObjectAddressDataTest.php`, `tests/Unit/Casts/ObjectAddressDataCastTest.php`

### Routes
```php
Route::prefix('v1')->group(function () {
    // Адрес точки самовывоза
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('customer/addresses/pickup', [AddressPickupController::class, 'storePickup']);
    });

    // Адреса
    Route::middleware('auth:sanctum')->prefix('addresses')->group(function () {
        Route::get('/', [AddressController::class, 'index']);
        Route::get('/active', [AddressController::class, 'active']);
        Route::post('/', [AddressController::class, 'store']);
        Route::put('/{address}', [AddressController::class, 'update']);
        Route::delete('/{address}', [AddressController::class, 'destroy']);
        Route::put('/{address}/active', [AddressController::class, 'setActive']);
    });

    // Фотографии адреса-объекта
    Route::middleware(['auth:sanctum', 'address-objects-enabled'])->group(function () {
        Route::post('addresses/{address}/photos', [AddressPhotoController::class, 'store'])
            ->middleware('throttle:10,1');
        Route::delete('addresses/{address}/photos/{photo}', [AddressPhotoController::class, 'destroy'])
            ->middleware('throttle:30,1');
    });
});
```
