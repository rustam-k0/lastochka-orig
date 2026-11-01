# ТЗ: Самовывоз (Pickup)

> Дополнение к `07_delivery.md`. Описывает слоты самовывоза из магазина, справочники пунктов выдачи СДЭК и Почты России, а также особенности заказов со `shippingMethod` = `pickup`, `cdek`, `russian_post`.

## 1. Охват

| Метод | URL | Auth | Контроллер |
|---|---|---|---|
| GET | `/api/v1/stores/{store}/pickup-slots` | `auth:sanctum` | `PickupSlotController` |
| GET | `/api/v1/pickup/cdek` | публичная (новое) | `Delivery\PickupController::cdek` |
| GET | `/api/v1/pickup/cdek/{code}` | публичная (новое) | `Delivery\PickupController::cdekShow` |
| GET | `/api/v1/pickup/russianpost` | публичная (новое) | `Delivery\PickupController::russianPost` |
| GET | `/api/v1/pickup/russianpost/{index}` | публичная (новое) | `Delivery\PickupController::russianPostShow` |
| GET | `/api/v1/pickup/cities` | публичная (новое) | `Delivery\PickupController::cities` |

Смежные документы:

- `07_delivery.md`: курьерские слоты, доступные способы доставки (`GET /delivery/options`), тарифы СДЭК и Почты России, расчёт доставки (`GET /delivery/calculate`).
- `02_addresses.md`: сохранение выбранного ПВЗ как адреса пользователя (`POST /v1/customer/addresses/pickup`).
- `06_orders.md`: создание заказа.
- `2026-07-05-pickup-only-restriction-api-contract.md`: товары с признаком «только самовывоз».

Различаются два разных механизма:

- **Самовывоз из магазина** (`shippingMethod: "pickup"`): слоты генерируются локально по расписанию магазина, внешние системы не участвуют, доставка бесплатна.
- **Пункты выдачи сторонних служб** (`shippingMethod: "cdek"` или `"russian_post"`): справочники и тарифы приходят из slots-api модуля `purchase_back`, слоты не используются.

## 2. GET /api/v1/stores/{store}/pickup-slots `[AUTH]`

### 2.1 Запрос

`{store}` резолвится через implicit route model binding, после чего контроллер повторно загружает магазин с фильтром `active()`. Несуществующий магазин и магазин с `is_active = false` дают 404.

**Query:**

| Параметр | Тип | Обязательность | Правила |
|---|---|---|---|
| `date` | string | required | `date_format:Y-m-d` |

Кастомных текстов ошибок у этой валидации нет, отдаются стандартные сообщения Laravel.

### 2.2 Ответ 200

```json
{
  "data": {
    "slots": [
      {"id": 1735635600, "timeSlot": "10:00–11:00"},
      {"id": 1735639200, "timeSlot": "11:00–12:00"}
    ],
    "minOrderAmount": 500
  }
}
```

| Поле | Тип | Описание |
|---|---|---|
| `slots[].id` | int | Unix timestamp начала слота в таймзоне приложения (`config('app.timezone')` = `Europe/Moscow`) |
| `slots[].timeSlot` | string | `HH:MM–HH:MM`; разделитель это короткое тире U+2013 без пробелов вокруг, в отличие от курьерских слотов, где стоит ASCII-дефис |
| `minOrderAmount` | int | `store.min_pickup_order_amount`, при null это `store.min_order_amount`, при null это 0 |

Если подходящих слотов нет (выходной день, все слоты уже прошли, товары не готовы к концу рабочего дня), возвращается `"slots": []` со статусом 200.

### 2.3 Генерация слотов

Слоты строит `PickupSlotService::getSlots(store, date, readyFrom)`, обращений к внешним системам нет.

- Длительность слота: `store.slot_duration`, при null это 60 минут.
- Часы работы: при `store.is_open_24_7 = true` окно равно 00:00 запрошенной даты до 00:00 следующего дня. Иначе берётся `store_schedules` по номеру дня недели (`dayOfWeekIso`, 1 это понедельник). Если записи нет или `opens_at` пуст, возвращается пустой список.
- Слоты нарезаются подряд от открытия; слот, конец которого выходит за время закрытия, не создаётся.
- Для сегодняшней даты слоты, начало которых раньше текущего момента, пропускаются.
- Готовность товаров (модуль `product_ready_time`): слоты, начинающиеся раньше `readyFrom`, пропускаются.

`readyFrom` считает `ReadyTimeResolver::forUserStore(user, store.id, дата запроса)`: это максимум `product.readyAt()` по позициям корзины пользователя в этом магазине, включая дочерние позиции (добавки из каталога). Значение null отдаётся, когда модуль `product_ready_time` выключен, корзины нет или все товары готовы. Фиксированная готовность резолвится на запрошенную дату, поэтому утренние слоты будущих дат фильтруются так же, как сегодняшние.

### 2.4 Ошибки

| Код | Тело | Условие |
|---|---|---|
| 401 | стандартное тело Sanctum | Запрос без токена |
| 404 | стандартное тело | Магазин не найден или неактивен |
| 422 | `{"message": ..., "errors": {"date": [...]}}` | `date` не передан или не в формате `Y-m-d` |
| 422 | `{"error": "pickup_not_available", "message": "Самовывоз недоступен для этого магазина"}` | У магазина `is_pickup_enabled = false` |

Проверка `is_pickup_enabled` выполняется после валидации `date` и после загрузки активного магазина.

## 3. Справочники пунктов выдачи (новое)

Пять публичных ручек проксируют slots-api (`{SLOTS_API_BASE_URL}`). Валидации query-параметров нет, пустые и null-значения отбрасываются перед отправкой. При недоступности slots-api или его ошибке списочные ручки отвечают 200 с `{"data": []}`, а ручки одной точки отвечают 404: клиент логирует предупреждение и возвращает пустое значение.

Общая форма элемента справочника по контракту `SlotsApiClientInterface`:

| Поле | Тип |
|---|---|
| `provider` | string |
| `external_id` | string |
| `code` | string |
| `title` | string |
| `address` | string |
| `city` | string |
| `postalCode` | string |
| `lat` | float |
| `lng` | float |
| `workTime` | string |
| `phone` | string |
| `type` | string |

Содержимое передаётся от slots-api как есть, storefront его не пересобирает.

### 3.1 GET /api/v1/pickup/cdek

**Query:**

| Параметр | Тип | Описание |
|---|---|---|
| `bbox` | string | Ограничивающий прямоугольник `lat1,lng1,lat2,lng2` |
| `q` | string | Строка поиска |
| `limit` | int | Максимальное количество результатов |
| `type` | string | Тип точки: `PVZ` или `POSTAMAT` |

Если `type` не передан, клиент подставляет `PVZ`.

Внешний вызов: `GET /api/v1/slots/cdek-pvz`.

**Ответ 200:** `{"data": [ ... ]}`.

### 3.2 GET /api/v1/pickup/cdek/{code}

Внешний вызов: `GET /api/v1/slots/cdek-pvz/{code}`.

- 200: `{"data": { ... }}`.
- 404: `{"message": "ПВЗ СДЭК не найден"}`. Тот же ответ отдаётся при недоступности slots-api или любой его ошибке, поскольку клиент в обоих случаях возвращает null.

### 3.3 GET /api/v1/pickup/russianpost

**Query:**

| Параметр | Тип | Описание |
|---|---|---|
| `bbox` | string | Ограничивающий прямоугольник `lat1,lng1,lat2,lng2` |
| `q` | string | Строка поиска |
| `limit` | int | Максимальное количество результатов |
| `opsType` | string | Тип отделения |

Если `opsType` не передан, клиент подставляет `ГОПС`.

Внешний вызов: `GET /api/v1/slots/rp-offices`.

**Ответ 200:** `{"data": [ ... ]}`.

### 3.4 GET /api/v1/pickup/russianpost/{index}

Параметр маршрута ограничен регулярным выражением `\d{6}`: значение другой длины или с нецифровыми символами под маршрут не подходит и даёт 404 ещё до контроллера.

Внешний вызов: `GET /api/v1/slots/rp-offices/{index}`.

- 200: `{"data": { ... }}`.
- 404: `{"message": "Отделение Почты России не найдено"}`, в том числе при ошибке slots-api.

### 3.5 GET /api/v1/pickup/cities

**Query:**

| Параметр | Тип | Описание |
|---|---|---|
| `q` | string | Строка поиска по названию города |
| `limit` | int | Максимальное количество результатов |

Внешний вызов: `GET /api/v1/slots/pickup-cities`.

**Ответ 200:**

```json
{
  "data": [
    {"name": "Москва", "slug": "moskva", "region": "Москва", "zip": "101000", "lat": 55.7558, "lng": 37.6173}
  ]
}
```

### 3.6 Доступность СДЭК и Почты России

Ручки справочников не проверяют ни модули `delivery_cdek` / `delivery_russian_post`, ни активность интеграции магазина: они отвечают всегда. Состояние способов доставки для магазина отдаёт `GET /v1/delivery/options` (`07_delivery.md`), состояние с учётом содержимого корзины отдаёт поле `availableShippingMethods` в ответах корзины.

Тарифы для выбранной точки считает `GET /v1/delivery/cdek/tariffs` и `GET /v1/delivery/russianpost/tariffs` (`07_delivery.md`).

## 4. Сохранение точки выдачи как адреса

`POST /v1/customer/addresses/pickup` создаёт адрес пользователя по выбранному ПВЗ и описан в `02_addresses.md`. Для заказов существенно, что адрес сохраняется с `is_active = false` и с блоком `additional`, где `kind = "pickup"`, `provider` равен `cdek` или `russian_post`, а `external_id` содержит код ПВЗ или индекс ОПС. Эти три значения проверяются при создании заказа.

## 5. Заказ с самовывозом

Полный контракт создания заказа: `06_orders.md`. Ниже только то, что определяется самовывозом.

### 5.1 Самовывоз из магазина (`shippingMethod: "pickup"`)

- `addressId` не требуется.
- `deliverySlotId` это `id` слота из `GET /stores/{store}/pickup-slots`.
- Магазин с `is_pickup_enabled = false` даёт 422: `{"message": "Самовывоз недоступен для этого магазина", "errors": {"shippingMethod": [...]}}`.
- Тип оплаты проверяется по `store.pickup_payment_methods`; если поле не массив, допустимым считается только `card`. Недопустимое значение даёт 422 с текстом «Выбранный способ оплаты недоступен для самовывоза». Для заказов сборщика (`POST /v1/client-orders`) та же проверка выполняется на верхнем уровне по фактическому типу оплаты, поэтому внутри создания дочернего заказа она пропускается.
- Минимальная сумма: `store.min_pickup_order_amount`, при null это `store.min_order_amount`.
- Слот перепроверяется тем же `PickupSlotService` на дату из timestamp слота и с той же логикой готовности товаров. Если слота нет в актуальном списке, возвращается 422 `{"message": "Слот доставки больше недоступен", "errors": {"deliverySlotId": [...]}}`.
- Конец слота в заказе считается как начало плюс `store.slot_duration`, при null это 60 минут.
- Стоимость доставки равна 0.
- В `OrderResource` поле `storeAddress` присутствует только у заказов со `shipping_method = pickup` и содержит `store.address`, а при его отсутствии `store.name`.

### 5.2 Пункт выдачи сторонней службы (`shippingMethod: "cdek"` или `"russian_post"`)

- `addressId` обязателен и должен указывать на адрес, сохранённый через `POST /v1/customer/addresses/pickup`. Если `additional.kind` не равен `pickup`, возвращается 422 `{"message": "Адрес должен быть пунктом выдачи заказов (pickup).", "errors": {"addressId": [...]}}`. Если `additional.provider` не совпадает с выбранным `shippingMethod`, возвращается 422 с текстом «Провайдер ПВЗ не совпадает с выбранным методом доставки.».
- Слот не используется: заказ сохраняется с фиктивным слотом (`id = 0`, пустой `timeSlot`, начало и конец равны моменту создания).
- Минимальная сумма считается так же, как для самовывоза из магазина: `store.min_pickup_order_amount`, при null это `store.min_order_amount`.
- Стоимость доставки берётся из тарифа провайдера: `ThirdPartyTariffPriceResolver` запрашивает актуальные тарифы через slots-api по `shopId`, коду точки и весу корзины и ищет переданный `shippingTariffCode`. Отсутствие `external_id` в адресе, отсутствие `shippingTariffCode` или его отсутствие в актуальном списке дают 422.
- В заказе сохраняются `shipping_tariff_code`, `pickup_external_id` и снимок данных точки `pickup_address_data`.

### 5.3 Таймер авто-отмены заказа с самовывозом

Когда pickup-заказ переходит в статус `assembled` (событие из курьерки), сервер ставит таймер авто-отмены, если у магазина `pickup_auto_cancel_enabled = true`. У остальных магазинов таймер не ставится.

- Часы ожидания: если хотя бы один товар заказа входит в категорию с `is_perishable = true`, берётся `store.pickup_wait_perishable_hours` (при null это 1), иначе `store.pickup_wait_regular_hours` (при null это 3).
- К ним прибавляется `store.pickup_grace_period_hours` (при null это 1).
- `pickup_timer_expires_at` равен моменту перехода в `assembled` плюс сумма этих часов и отдаётся в `OrderResource` полем `pickupTimerExpiresAt` в формате ISO 8601; поле может быть null.

Команда `pickup:auto-cancel` запускается планировщиком каждую минуту, забирает до 100 заказов со `shipping_method = pickup`, статусом `assembled` и истёкшим `pickup_timer_expires_at` и по каждому: возвращает товары на остатки, возвращает списанные бонусы, переводит заказ в `cancelled`, обнуляет `pickup_timer_expires_at`, для оплаты картой делает возврат или снимает холд, публикует событие отмены и отправляет пуш о статусе.

### 5.4 Товары «только самовывоз»

Если в корзине есть хотя бы один товар с внутренним признаком «только самовывоз», любой способ доставки кроме `pickup` даёт 422 `{"message": "В заказе есть товар, доступный только для самовывоза", "errors": {"shippingMethod": [...]}}`. Ограничение действует и на `own_courier`, и на Яндекс-доставку, и на ПВЗ сторонних служб. Полный контракт: `2026-07-05-pickup-only-restriction-api-contract.md`.

## 6. Данные магазина

Поля магазина, влияющие на самовывоз (отдаются в `StoreResource`, см. `03_stores.md`):

| Поле модели | Поле ответа | Назначение |
|---|---|---|
| `is_pickup_enabled` | `isPickupEnabled` | Разрешён ли самовывоз |
| `pickup_payment_methods` | `pickupPaymentMethods` | Допустимые типы оплаты при самовывозе; при null отдаётся `["card"]` |
| `min_pickup_order_amount` | `minPickupOrderAmount` | Минимальная сумма заказа при самовывозе; при null это `min_order_amount`, при null это 0 |
| `slot_duration` | нет | Длительность слота самовывоза в минутах; при null это 60 |
| `is_open_24_7` | нет | Круглосуточный режим для генерации слотов |
| `pickup_auto_cancel_enabled` | нет | Включён ли таймер авто-отмены собранного заказа |
| `pickup_wait_perishable_hours` | нет | Часы ожидания для заказа со скоропортящимся товаром; при null это 1 |
| `pickup_wait_regular_hours` | нет | Часы ожидания для обычного заказа; при null это 3 |
| `pickup_grace_period_hours` | нет | Дополнительные часы к сроку ожидания; при null это 1 |

Значения `is_pickup_enabled`, `pickup_payment_methods` и `min_pickup_order_amount` приходят из курьерки через RabbitMQ (`StoreEventTransformer`).

## 7. Routes

```php
Route::prefix('v1')->group(function () {
    Route::get('stores/{store}/pickup-slots', PickupSlotController::class)
        ->middleware('auth:sanctum');

    Route::prefix('pickup')->group(function () {
        Route::get('cdek', [PickupController::class, 'cdek']);
        Route::get('cdek/{code}', [PickupController::class, 'cdekShow']);
        Route::get('russianpost', [PickupController::class, 'russianPost']);
        Route::get('russianpost/{index}', [PickupController::class, 'russianPostShow'])->where('index', '\d{6}');
        Route::get('cities', [PickupController::class, 'cities']);
    });
});
```

## 8. Реализация

Классы:

- `App\Http\Controllers\Api\V1\PickupSlotController`
- `App\Http\Controllers\Api\V1\Delivery\PickupController`
- `App\Services\Pickup\PickupSlotService`
- `App\Services\ReadyTime\ReadyTimeResolver`
- `App\Services\SlotsApi\SlotsApiClient` (в тестах подменяется на `FakeSlotsApiClient`)
- `App\Services\Delivery\ThirdPartyTariffPriceResolver`
- `App\Actions\Order\AutoCancelPickupOrderAction` и `App\Console\Commands\AutoCancelPickupOrdersCommand` (`pickup:auto-cancel`, в планировщике каждую минуту)
- `App\Enums\ShippingMethod` (`courier`, `pickup`, `yandex_delivery`, `cdek`, `russian_post`; `isPickupPoint()` истинно для `cdek` и `russian_post`)

Тесты: `tests/Feature/PickupReadyTimeTest.php`, `tests/Feature/Api/V1/Delivery/Pickup/PickupCdekTest.php`.
