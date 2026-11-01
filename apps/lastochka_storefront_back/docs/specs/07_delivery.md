# ТЗ: Доставка (слоты, способы, тарифы, расчёт)

## 1. Охват

Модуль Delivery. Файл описывает поведение следующих ручек:

| Метод | URL | Auth | Контроллер |
|---|---|---|---|
| GET | `/api/v1/stores/{store}/delivery-slots` | `auth:sanctum` | `DeliveryController::slots` |
| GET | `/api/v1/delivery/options` | публичная (новое) | `Delivery\DeliveryOptionsController::index` |
| GET | `/api/v1/delivery/cdek/tariffs` | публичная (новое) | `Delivery\DeliveryTariffsController::cdek` |
| GET | `/api/v1/delivery/russianpost/tariffs` | публичная (новое) | `Delivery\DeliveryTariffsController::russianPost` |
| GET | `/api/v1/delivery/calculate` | `auth:sanctum` (новое) | `Delivery\DeliveryCalculateController::index` |

Смежные документы:

- `27_pickup.md`: слоты самовывоза из магазина (`GET /stores/{store}/pickup-slots`) и справочники ПВЗ СДЭК / ОПС Почты России (`GET /pickup/*`).
- `02_addresses.md`: сохранение выбранного ПВЗ как адреса пользователя (`POST /v1/customer/addresses/pickup`).
- `06_orders.md`: применение слота и способа доставки при создании заказа.

Все перечисленные маршруты объявлены внутри группы `Route::prefix('v1')`. Отдельный throttle на них не назначен, действуют только общие middleware группы `api`.

Внешняя система для курьерских слотов и тарифов сторонних служб одна: slots-api модуля `purchase_back`. Storefront обращается к ней двумя клиентами: `DeliverySystemClient` (слоты, отдельный базовый URL и Bearer-токен) и `SlotsApiClient` (магазины по координатам, ПВЗ, тарифы, конфигурации интеграций).

## 2. GET /api/v1/stores/{store}/delivery-slots `[AUTH]`

Возвращает слоты курьерской доставки для магазина и адреса пользователя на дату.

### 2.1 Запрос

`{store}` резолвится через implicit route model binding по первичному ключу. Фильтра по `is_active` нет: слоты отдаются и для неактивного магазина, несуществующий id даёт 404.

Query-параметры (`GetDeliverySlotsRequest`):

| Параметр | Тип | Обязательность | Правила и значение по умолчанию |
|---|---|---|---|
| `addressId` | int | required | `exists:addresses,id` с дополнительным условием `user_id = id текущего пользователя` |
| `date` | string | nullable | `date_format:Y-m-d`. При отсутствии подставляется `now()->format('Y-m-d')` в таймзоне приложения (`config('app.timezone')` = `Europe/Moscow`) |

Тексты ошибок валидации заданы в `messages()`:

- `addressId.required`: «Укажите адрес доставки»
- `addressId.exists`: «Адрес не найден»
- `date.date_format`: «Неверный формат даты. Ожидается: YYYY-MM-DD»

Проверка принадлежности адреса выполняется правилом `exists` внутри FormRequest, поэтому чужой или удалённый адрес даёт 422 по полю `addressId`, а не 403.

### 2.2 Состав корзины в запросе слотов

Перед вызовом внешнего API сервер собирает позиции корзины пользователя по этому магазину (`CartProductsMapper::mapUserStoreItems`) и передаёт их в slots-api, потому что доступность слота зависит от времени сборки заказа (`shops_categories.time_to_take` в курьерке).

Правила маппинга (`CartProductsMapper`):

- берутся `cart_items` корзины пользователя, отфильтрованные по `store_id` магазина;
- позиция без `product.sku` пропускается;
- одинаковые `sku` суммируются, результат: `[{"articul": "<sku>", "count": <int>}]`;
- `count` считается в порциях: для `measurement_unit` = `kilogram` или `liter` с `quantity_step <= 1` это `round(quantity / 1000)`, но не меньше 1; при `quantity_step > 1` это `intdiv(quantity, quantity_step)`; в остальных случаях это `quantity`.

Состав корзины входит в ключ кеша (см. 2.7), поэтому изменение корзины сразу меняет выдачу слотов.

### 2.3 Запрос к DeliverySystem

**URL:** `POST {DELIVERY_SYSTEM_URL}/api/v1/slots/available`

**Headers:**

```
Authorization: Bearer {DELIVERY_SYSTEM_API_KEY}
Content-Type: application/json
```

**Request:**

```json
{
  "code": "store-code",
  "latitude": 55.7558,
  "longitude": 37.6173,
  "date": "2026-01-15",
  "products": [
    {"articul": "12345", "count": 2}
  ]
}
```

`code` это `store.code`. Ключ `products` добавляется только если массив непустой.

Таймаут запроса: `config('services.delivery_system.timeout')` (по умолчанию 10 секунд). Повтор: 1 раз с паузой 100 мс (`->retry(1, 100)`).

**Response внешней системы:**

```json
{
  "slots": [
    {"start": "2026-01-15 10:00:00", "end": "2026-01-15 12:00:00"},
    {"start": "2026-01-15 12:00:00", "end": "2026-01-15 14:00:00"}
  ],
  "min_order_price": 500,
  "shipping_price": 200,
  "shipping_free_from": 1500,
  "delivery_type": "own",
  "assembly_price": 150
}
```

Разбор ответа (`DeliveryInfoData::fromApiResponse`):

- `min_order_price` и `shipping_price` читаются без значения по умолчанию;
- `shipping_free_from` необязателен, при отсутствии `freeDeliveryFrom = null`;
- `delivery_type` необязателен, при отсутствии `'own'`; значение `'yandex'` включает обогащение Яндекс-доставкой (см. 2.5);
- `assembly_price` необязателен, при отсутствии `assemblyPrice = null`; в ответ ручки это поле не попадает, оно используется корзиной и заказом (`05_cart.md`, `06_orders.md`);
- строки `start` и `end` разбираются `Carbon::parse` в таймзоне приложения.

### 2.4 Ответ 200

Ресурс `DeliveryInfoResource` отдаётся без обёртки `data` (`$wrap = null`).

```json
{
  "slots": [
    {"id": 1705312800, "timeSlot": "10:00-12:00", "date": "2026-01-15T10:00:00+03:00"},
    {"id": 1705320000, "timeSlot": "12:00-14:00", "date": "2026-01-15T12:00:00+03:00"}
  ],
  "minOrderAmount": 500,
  "freeDeliveryFrom": 1500,
  "deliveryPrice": 200,
  "availablePaymentMethods": ["card", "cash"]
}
```

| Поле | Тип | Описание |
|---|---|---|
| `slots[].id` | int | Unix timestamp начала слота |
| `slots[].timeSlot` | string | `HH:MM-HH:MM`, разделитель это ASCII-дефис |
| `slots[].date` | string | ISO 8601 момента начала слота (`startAt->toIso8601String()`), включает смещение таймзоны |
| `minOrderAmount` | int | Минимальная сумма заказа для зоны |
| `freeDeliveryFrom` | int \| null | Порог бесплатной доставки, может быть null |
| `deliveryPrice` | int | Стоимость доставки |
| `availablePaymentMethods` | string[] | `["card", "cash"]` для `deliveryType = own`; `["card"]` для `deliveryType = yandex` |
| `yandexDelivery` | object | Присутствует только если блок Яндекс-доставки был сформирован (см. 2.5) |

Пустой список слотов возвращается как `"slots": []` со статусом 200.

### 2.5 Обогащение Яндекс-доставкой

Блок формируется, только когда slots-api вернул `delivery_type = "yandex"` **и** в таблице `integration_configs` есть запись с `integration_type = 'delivery'` и `is_active = true`. Если интеграция не активна, ответ остаётся зонным, поля `yandexDelivery` в нём нет, но `availablePaymentMethods` уже равно `["card"]`, потому что ресурс смотрит на `deliveryType`.

Порядок обработки (`DeliveryService::enrichWithYandexDelivery`):

1. Слоты фильтруются по окну `startAt <= now() + maxDueHours`, где `maxDueHours` это `config('yandex-delivery.max_due_hours')` (по умолчанию 4, env `YANDEX_DELIVERY_MAX_DUE_HOURS`).
2. Запрашиваются способы доставки Яндекса по координатам магазина и адреса. Если экспресс-доставка не разрешена, возвращается отфильтрованный список слотов, зонная `deliveryPrice` и `yandexDelivery` в состоянии «недоступно».
3. Запрашивается цена (`checkPrice`). Любое исключение даёт тот же результат, что и шаг 2: зонная цена и `yandexDelivery` в состоянии «недоступно».
4. Если после фильтрации слотов не осталось, возвращается `"slots": []`, `deliveryPrice` равна округлённой вверх цене Яндекса, `yandexDelivery` в состоянии «недоступно».
5. Иначе `deliveryPrice` равна округлённой вверх цене Яндекса, а `yandexDelivery` заполняется полностью.

```json
{
  "yandexDelivery": {
    "available": true,
    "price": 450,
    "eta": 16.5,
    "description": "Доставка Яндекс Go",
    "availableSlots": [
      {"slotId": 1705312800, "start": "10:00", "end": "12:00"}
    ],
    "maxDueHours": 4
  }
}
```

| Поле | Тип | Описание |
|---|---|---|
| `available` | bool | `false` в сценариях 2, 3 и 4 выше |
| `price` | int \| null | `ceil` цены Яндекса; `null`, когда `available = false` |
| `eta` | float \| null | Значение `eta` из ответа Яндекса; единица измерения в коде storefront не зафиксирована. `null`, когда `available = false` |
| `description` | string \| null | Всегда `"Доставка Яндекс Go"` при `available = true`, иначе `null` |
| `availableSlots` | array | Элементы `{slotId: int, start: "HH:MM", end: "HH:MM"}`; пустой массив, когда `available = false` |
| `maxDueHours` | int | Значение `config('yandex-delivery.max_due_hours')`, в состоянии «недоступно» отдаётся дефолт DTO, равный 4 |

Режим заноса («до двери» или «от подъезда») выбирается автоматически по наличию номера дома в адресе (`YandexGoClient::shouldSkipDoorToDoor`), тот же критерий применяется при создании заявки, поэтому оценка в слотах и списание в заказе совпадают.

Стоимость, показываемая в корзине, считается тем же способом (`DeliveryService::resolveDisplayedDeliveryCost`): при достижении `freeDeliveryFrom` она равна 0, для зоны Яндекса берётся реальная цена `checkPrice` с откатом на зонную `deliveryPrice` при ошибке.

### 2.6 Ошибки

| Код | Тело | Условие |
|---|---|---|
| 401 | стандартное тело Sanctum | Запрос без токена |
| 404 | стандартное тело | Магазин с таким id не найден |
| 422 | `{"message": ..., "errors": {"addressId": [...]}}` | Валидация `addressId`: не передан, не число, не существует или принадлежит другому пользователю |
| 422 | `{"message": ..., "errors": {"date": [...]}}` | `date` не в формате `Y-m-d` |
| 422 | `{"message": "У адреса отсутствуют корректные координаты. Укажите адрес с координатами.", "errors": {"addressId": ["У адреса отсутствуют корректные координаты."]}}` | У адреса `latitude` или `longitude` равны null, либо обе координаты равны 0 |
| 422 | `{"error": "zone_not_found", "message": "Доставка по указанным координатам недоступна"}` | slots-api ответил ошибкой с полем `error = zone_not_found` |
| 503 | `{"message": "<текст ошибки>"}` | slots-api недоступен или вернул иную ошибку, и в кеше нет данных для отката |

Проверка координат выполняется в контроллере до обращения к внешнему API: координаты `0,0` считаются некорректными.

### 2.7 Кеширование и fallback

- Ключ: `delivery_slots:{store.code}:{latitude}:{longitude}:{date}:{md5(json_encode(products))}`.
- Запись: только после успешного ответа slots-api, TTL 30 минут (`Cache::put(..., now()->addMinutes(30))`).
- Чтение: только в ветке отката. Успешный запрос всегда идёт во внешнюю систему, кеш при этом перезаписывается.
- Откат: при `DeliverySystemException` возвращается значение из кеша, если запись жива; иначе исключение отдаёт 503. Записи с истёкшим TTL недоступны, отката по ним не происходит.
- `DeliveryZoneNotFoundException` пробрасывается сразу, кеш не читается.

### 2.8 Слот при создании заказа

`DeliveryService::validateSlot(store, address, slotId, products)` вызывается из `CreateOrderAction` для курьерской доставки и доставки Яндекса:

1. Дата вычисляется из timestamp слота в таймзоне приложения (`Carbon::createFromTimestamp($slotId, config('app.timezone'))`).
2. Слоты запрашиваются повторно с тем же составом корзины, что видел клиент, иначе слот, недоступный из-за времени сборки, прошёл бы проверку.
3. Если слота с таким `id` в актуальном списке нет, `DeliverySlotUnavailableException` отдаёт 422:

```json
{
  "message": "Слот доставки больше недоступен",
  "errors": {"deliverySlotId": ["Слот доставки больше недоступен"]}
}
```

Для доставки Яндекса дополнительно проверяется, что начало слота не позже `now() + maxDueHours`, иначе возвращается та же ошибка 422, и что тип оплаты не наличные (иначе 422 по полю `paymentType`). Детали создания заказа: `06_orders.md`.

## 3. GET /api/v1/delivery/options (новое)

Публичная ручка. Возвращает состояние трёх способов доставки для магазина.

**Query:**

| Параметр | Тип | Обязательность | Поведение |
|---|---|---|---|
| `shopId` | int | В OpenAPI помечен обязательным, но валидации нет | Приводится к int; при отсутствии подставляется 0 |

**Ответ 200:**

```json
{
  "data": [
    {"method": "own_courier", "label": "Доставка курьером", "available": true, "reason": null},
    {"method": "cdek", "label": "ПВЗ СДЭК", "available": false, "reason": "Магазин не подключил СДЭК"},
    {"method": "russian_post", "label": "ОПС Почты России", "available": true, "reason": null}
  ]
}
```

Массив всегда состоит из трёх элементов в этом порядке. `reason` равен null, когда `available = true`.

Правила доступности:

- `own_courier`: всегда `available: true`, `reason: null`.
- `cdek`: `available = (модуль delivery_cdek включён) && (интеграция магазина delivery_cdek активна)`. Если модуль выключен, `reason` равен «Модуль выключен»; если модуль включён, а интеграция нет, `reason` равен «Магазин не подключил СДЭК».
- `russian_post`: та же логика по модулю `delivery_russian_post`, текст второй причины «Магазин не подключил Почту России».

Состояние модулей читается из `StorefrontSetting.project_modules` через `ModuleConfigService` с кешем на 10 минут. Оба модуля fail-safe: если настройка отсутствует или чтение упало с ошибкой, модуль считается включённым.

Активность интеграций запрашивается у slots-api: `GET {SLOTS_API_BASE_URL}/api/v1/slots/integration-configs/{shopId}` с массивом `types`, содержащим `delivery_cdek` и `delivery_russian_post`. Ответ вида `{"data": [{"integration_type": ..., "is_active": ...}]}` раскладывается по типам; отсутствующий в ответе тип считается неактивным. При недоступности slots-api клиент возвращает пустой список, поэтому обе интеграции считаются неактивными и ручка отдаёт `available: false` с причиной «Магазин не подключил ...».

Самовывоз из магазина в этот список не входит. Способы доставки с учётом содержимого корзины (четыре элемента, включая `pickup`) отдаёт корзина: `05_cart.md` и `2026-07-05-pickup-only-restriction-api-contract.md`.

## 4. GET /api/v1/delivery/cdek/tariffs (новое)

Публичная ручка. Рассчитывает тарифы СДЭК для точки выдачи или города.

**Query:**

| Параметр | Тип | Обязательность | Правила |
|---|---|---|---|
| `shopId` | int | required | `integer`, `min:1` |
| `code` | string | nullable | `max:64`, код ПВЗ |
| `city` | string | nullable | `max:200`, название города |
| `weight` | int | nullable | `integer`, `min:1`, граммы |
| `dimensions` | string | nullable | `max:50`, формат `LxWxH` в сантиметрах |
| `declaredValue` | int | nullable | `integer`, `min:0`, рубли |

Дополнительное правило: должен быть передан `code` или `city`. Иначе 422 с `errors.code[0]` = «Необходимо указать код ПВЗ (code) или название города (city)».

Тексты ошибок по `shopId`: «Укажите ID магазина», «ID магазина должен быть числом», «ID магазина должен быть положительным числом».

Запрос к внешней системе: `GET {SLOTS_API_BASE_URL}/api/v1/slots/delivery/cdek/tariffs` с теми же параметрами; пустые и null-значения отбрасываются.

**Ответ 200:** `{"data": [ ... ]}`, где содержимое массива передаётся от slots-api как есть. Storefront не нормализует структуру тарифа; из его полей код использует `code` (идентификатор тарифа) и `price` (цена в рублях, см. `ThirdPartyTariffPriceResolver`).

При ошибке или недоступности slots-api ручка отвечает 200 с `{"data": []}`: клиент логирует предупреждение и возвращает пустой массив.

## 5. GET /api/v1/delivery/russianpost/tariffs (новое)

Публичная ручка. Рассчитывает тарифы Почты России для отделения.

**Query:**

| Параметр | Тип | Обязательность | Правила |
|---|---|---|---|
| `shopId` | int | required | `integer`, `min:1` |
| `index` | string | required | `regex:/^\d{6}$/` |
| `weight` | int | nullable | `integer`, `min:1`, граммы |

Тексты ошибок: «Укажите почтовый индекс», «Почтовый индекс должен состоять из 6 цифр», плюс те же три текста по `shopId`, что в разделе 4.

Запрос к внешней системе: `GET {SLOTS_API_BASE_URL}/api/v1/slots/delivery/rp/tariffs`.

**Ответ 200:** `{"data": [ ... ]}` в том же формате пробрасывания, что и у СДЭК. При ошибке slots-api это `{"data": []}` со статусом 200.

## 6. GET /api/v1/delivery/calculate `[AUTH]` (новое)

Рассчитывает доставку для выбранного способа. Ветвление по `shippingMethod`.

**Query:**

| Параметр | Тип | Обязательность | Правила |
|---|---|---|---|
| `shippingMethod` | string | required | `in:own_courier,cdek,russian_post` |
| `shopId` | int | required | `integer`, `min:1` |
| `externalId` | string | nullable | `max:64`, код ПВЗ СДЭК или индекс ОПС |
| `tariffCode` | string | nullable | `max:64` |
| `addressId` | int | nullable | `integer`, `min:1` |

Тексты ошибок: «Укажите способ доставки», «Способ доставки должен быть own_courier, cdek или russian_post», «Укажите ID магазина».

### 6.1 shippingMethod = own_courier

- Без `addressId` возвращается 422 с `errors.addressId[0]` = «Для own_courier необходимо указать addressId».
- Магазин ищется по `shopId` без фильтра активности; если не найден, 404 `{"message": "Магазин не найден"}`.
- Адрес ищется по `addressId`; если не найден, 404 `{"message": "Адрес не найден"}`. Принадлежность адреса текущему пользователю здесь не проверяется, в отличие от `/stores/{store}/delivery-slots`.
- Состав корзины подставляется тем же `CartProductsMapper`, что в разделе 2.2.
- Дата фиксирована: всегда сегодняшняя (`now()->toDateString()`), параметра `date` у ручки нет.
- Расчёт идёт через тот же `DeliveryService::getDeliveryInfo`, поэтому действуют кеш и откат из раздела 2.7, обогащение Яндексом из раздела 2.5, а ошибки `zone_not_found` (422) и недоступности slots-api (503) выходят наружу в том же виде, что в разделе 2.6.

**Ответ 200:**

```json
{
  "data": {
    "shippingMethod": "own_courier",
    "deliveryPrice": 200,
    "minOrderAmount": 500,
    "freeDeliveryFrom": 1500,
    "slots": [
      {"id": 1705312800, "timeSlot": "10:00-12:00"}
    ]
  }
}
```

Отличия от `/stores/{store}/delivery-slots`: ответ обёрнут в `data`, у слота нет поля `date`, блок `yandexDelivery` и `availablePaymentMethods` не возвращаются. Для зоны Яндекса `deliveryPrice` уже содержит округлённую цену Яндекса, а список слотов обрезан окном `maxDueHours`.

### 6.2 shippingMethod = cdek или russian_post

- Без `externalId` возвращается 422 с текстом «Для cdek необходимо указать externalId (код ПВЗ/индекс ОПС)» (для `russian_post` в тексте подставляется соответствующее значение).
- Тарифы запрашиваются у slots-api: для `cdek` по параметрам `shopId` и `code = externalId`, для `russian_post` по `shopId` и `index = externalId`. Вес, габариты и объявленная стоимость этой ручкой не передаются.
- Если передан `tariffCode`, список фильтруется по совпадению поля `code` элемента. Несовпадение даёт пустой массив, а не ошибку.

**Ответ 200:**

```json
{
  "data": {
    "shippingMethod": "cdek",
    "tariffs": [ ... ]
  }
}
```

Недоступность slots-api даёт `"tariffs": []` со статусом 200.

Итоговая цена доставки в заказе для ПВЗ считается отдельно, при создании заказа: `ThirdPartyTariffPriceResolver` заново запрашивает тарифы с фактическим весом корзины и ищет `shippingTariffCode`. Отсутствие тарифа в актуальном списке даёт 422 с текстом «Выбранный тариф доставки больше недоступен. Обновите список тарифов и попробуйте снова.». Детали: `06_orders.md`.

## 7. DTO

### 7.1 DeliverySlotData

```php
final readonly class DeliverySlotData
{
    public function __construct(
        public int $id,          // Unix timestamp начала слота
        public string $timeSlot, // "10:00-12:00"
        public Carbon $startAt,
        public Carbon $endAt,
    ) {}
}
```

- `fromApiResponse(['start' => ..., 'end' => ...])`: `id` это `startAt->timestamp`, `timeSlot` это `start->format('H:i').'-'.$end->format('H:i')`.
- `fromSlotId(int $slotId, int $durationHours = 2)`: восстанавливает слот из id в таймзоне приложения, конец слота это начало плюс `durationHours`.

### 7.2 DeliveryInfoData

```php
final readonly class DeliveryInfoData
{
    public function __construct(
        public array $slots,                          // DeliverySlotData[]
        public int $minOrderAmount,
        public ?int $freeDeliveryFrom,
        public int $deliveryPrice,
        public string $deliveryType = 'own',          // 'own' | 'yandex'
        public ?YandexDeliveryInfoData $yandexDelivery = null,
        public ?int $assemblyPrice = null,            // зонная стоимость сборки
    ) {}
}
```

`assemblyPrice` равен null, когда у зоны нет своей цены сборки или пакет slots-api ещё не отдаёт это поле; в таком случае потребитель падает на `store.assembly_price`.

### 7.3 YandexDeliveryInfoData

```php
readonly class YandexDeliveryInfoData
{
    public function __construct(
        public bool $available,
        public ?int $price = null,
        public ?float $eta = null,
        public ?string $description = null,
        public array $availableSlots = [],
        public int $maxDueHours = 4,
    ) {}
}
```

## 8. Конфигурация

```php
// config/services.php
'delivery_system' => [
    'url' => env('DELIVERY_SYSTEM_URL'),
    'api_key' => env('DELIVERY_SYSTEM_API_KEY'),
    'timeout' => env('DELIVERY_SYSTEM_TIMEOUT', 10),
],

'slots_api' => [
    'base_url' => env('SLOTS_API_BASE_URL'),
    'timeout' => env('SLOTS_API_TIMEOUT', 10),
    'cache_ttl' => env('SLOTS_API_CACHE_TTL', 3600),
],
```

- `slots_api.cache_ttl` используется списком доступных магазинов по адресу (`AvailableStoreService`, см. `03_stores.md`), на слоты доставки он не влияет: там жёстко заданы 30 минут.
- `SlotsApiClient` работает с `connectTimeout(5)`, таймаутом `slots_api.timeout` и одним повтором через 1000 мс, причём повтор выполняется только для ошибок соединения и ответов со статусом 5xx или 0.
- Параметры Яндекс-доставки: `config/yandex-delivery.php`, в контракте ручек участвует `max_due_hours` (env `YANDEX_DELIVERY_MAX_DUE_HOURS`, по умолчанию 4). Включение самой интеграции определяется записью `integration_configs` с `integration_type = 'delivery'` и `is_active = true`, а не env-переменной.

## 9. Routes

```php
Route::prefix('v1')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('stores/{store}/delivery-slots', [DeliveryController::class, 'slots']);
    });

    Route::get('delivery/options', [DeliveryOptionsController::class, 'index']);

    Route::prefix('delivery')->group(function () {
        Route::get('cdek/tariffs', [DeliveryTariffsController::class, 'cdek']);
        Route::get('russianpost/tariffs', [DeliveryTariffsController::class, 'russianPost']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('customer/addresses/pickup', [AddressPickupController::class, 'storePickup']);
        Route::get('delivery/calculate', [DeliveryCalculateController::class, 'index']);
    });
});
```

## 10. Реализация

Классы:

- `App\Http\Controllers\Api\V1\DeliveryController`
- `App\Http\Controllers\Api\V1\Delivery\DeliveryOptionsController`, `DeliveryTariffsController`, `DeliveryCalculateController`
- `App\Http\Requests\Api\V1\Delivery\GetDeliverySlotsRequest`
- `App\Http\Resources\Api\V1\DeliveryInfoResource`, `DeliverySlotResource`
- `App\Services\Delivery\DeliveryService`, `DeliverySystemClient`, `CartProductsMapper`, `ThirdPartyTariffPriceResolver`
- `App\Services\SlotsApi\SlotsApiClient`
- `App\Services\Settings\ModuleConfigService`
- `App\DTOs\Delivery\DeliveryInfoData`, `DeliverySlotData`, `YandexDeliveryInfoData`, `YandexPriceData`, `YandexDeliveryMethodsData`
- `App\Exceptions\DeliverySystemException` (503), `DeliveryZoneNotFoundException` (422), `DeliverySlotUnavailableException` (422)

В тестовой среде `AppServiceProvider` подменяет `DeliveryClientInterface` на `FakeDeliveryClient`, `SlotsApiClientInterface` на `FakeSlotsApiClient`.

Тесты: `tests/Feature/Api/V1/Delivery/DeliveryTest.php`, `DeliverySlotsCartProductsTest.php`, `DeliveryOptionsTest.php`, `DeliveryCalculateTest.php`, `Tariffs/DeliveryCdekTariffsTest.php`, `tests/Feature/YandexDelivery/DeliverySlotsTest.php`, `tests/Unit/Services/SlotsApi/SlotsApiClientTest.php`.
