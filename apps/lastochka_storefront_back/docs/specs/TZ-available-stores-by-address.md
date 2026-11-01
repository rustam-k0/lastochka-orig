# ТЗ: Получение и кеширование доступных магазинов по координатам адреса

**Статус:** Утверждено
**Дата:** 2026-01-30

---

## 1. Цель

Автоматическое определение и кеширование списка магазинов, доступных для доставки по координатам выбранного адреса пользователя, с отдачей этих данных через API.

**Измеримый результат:**
- Клиентское приложение получает только те магазины, которые реально могут доставить заказ на указанный адрес
- Снижение нагрузки на внешний API за счёт кеширования
- UX: пользователь не видит магазины, недоступные для его локации

---

## 2. Принятые решения

| Вопрос | Решение |
|--------|---------|
| Хранение | **Только Redis кеш** (без БД) |
| Маппинг магазинов | По `store.code` (slots-api `shop.id` → наш `store.code`) |
| Fallback при ошибке API | **Пустой список** `{"data": [], "total": 0}` |
| TTL кеша | **1 час (3600 сек)** |

**Обоснование TTL = 1 час:**
- Зоны доставки меняются редко (обычно не чаще раза в день)
- При изменении координат адреса кеш инвалидируется автоматически
- 1 час покрывает типичную сессию пользователя
- Баланс между актуальностью и нагрузкой на slots-api

---

## 3. Контекст

### Где живёт
- Сервис: `app/Services/Store/AvailableStoreService.php`
- HTTP Client: `app/Services/SlotsApi/SlotsApiClient.php`

### Триггер
1. **Автоматический:** При установке/смене активного адреса у авторизованного пользователя
2. **Ручной:** При запросе списка магазинов с параметром `addressId`

### Связь с системой
- Интегрируется с внешним API `slots-api` (`GET /api/v1/slots/shops`)
- Использует существующие модели `Store`, `Address`
- Влияет на endpoint `GET /api/v1/stores`

### Внешняя зависимость
- **API:** `slots-api` (пакет `cron/slots-api`)
- **Endpoint:** `GET /api/v1/slots/shops?latitude={lat}&longitude={lng}`
- **Возвращает:** Список магазинов с `id`, `delivery_price`, `min_order_price`

---

## 4. Данные

### Входные данные

| Поле | Тип | Источник | Описание |
|------|-----|----------|----------|
| `addressId` | int | Request параметр | ID адреса пользователя |
| `latitude` | decimal(10,7) | Address.latitude | Координаты из адреса |
| `longitude` | decimal(10,7) | Address.longitude | Координаты из адреса |

### Структура кеша (Redis)

```
Key: available_stores:address:{address_id}
TTL: 3600 секунд (1 час)
Value: JSON
{
  "store_codes": ["shop_001", "shop_003", "shop_005"],
  "store_data": {
    "shop_001": {"delivery_price": 200, "min_order_price": 500},
    "shop_003": {"delivery_price": 150, "min_order_price": 300},
    "shop_005": {"delivery_price": 250, "min_order_price": 700}
  },
  "cached_at": "2024-01-15T10:30:00Z"
}
```

### Выходные данные

**Модификация `GET /api/v1/stores`:**

Новый query параметр:
- `addressId` (optional, int) — фильтровать по доступности для адреса

Расширенный ответ `StoreResource` (при наличии addressId):
```json
{
  "id": 1,
  "name": "Магазин №1",
  "minOrderAmount": 500,
  "workingHours": {...},
  "isActive": true,
  "image": {...},
  "rating": 4.5,
  "deliveryInfo": {
    "deliveryPrice": 200,
    "minOrderAmount": 500,
    "freeDeliveryFrom": 3000
  }
}
```

> `deliveryInfo.freeDeliveryFrom` — int|null, порог бесплатной доставки для зоны. Берётся из `slots-api` (`shop.free_shipping_from`).

**Пустой результат (нет доступных магазинов или ошибка API):**
```json
{
  "hasMore": false,
  "total": 0,
  "data": []
}
```

---

## 5. Логика

### Happy path

```
1. Пользователь авторизован и выбирает адрес доставки
2. Frontend вызывает GET /api/v1/stores?addressId={id}
3. Backend:
   a. Проверяет авторизацию
   b. Проверяет владение адресом (address.user_id === auth.id)
   c. Получает координаты из Address
   d. Проверяет кеш Redis (available_stores:address:{id})
   e. Если кеш есть → фильтрует Store по store_codes из кеша
   f. Если кеша нет:
      - Запрос к slots-api с координатами
      - Маппинг shop.id → store.code
      - Сохранение в кеш (TTL 1 час)
      - Фильтрация Store по полученным codes
   g. Возвращает список Store с deliveryInfo
```

### Сценарий обновления адреса

```
1. Пользователь редактирует адрес (меняются координаты)
2. AddressObserver::updated() срабатывает
3. Если изменились latitude или longitude:
   a. Инвалидировать кеш: Cache::forget("available_stores:address:{id}")
```

### Edge cases

| Кейс | Поведение |
|------|-----------|
| Нет Bearer-токена | Ошибка **401** `unauthorized` (маршрут публичный на middleware-уровне, но контроллер требует авторизацию) |
| У адреса нет координат | Ошибка 422: `address_missing_coordinates` |
| slots-api недоступен | Пустой список `{"data": [], "total": 0}` + лог |
| slots-api вернул пустой список | Пустой список (это валидный результат) |
| slots-api timeout | Пустой список + лог |
| Кеш expired | Запросить свежие данные, обновить кеш |
| Пользователь запрашивает чужой адрес | Ошибка **403** `forbidden` |
| addressId не передан | Вернуть все активные магазины **с** `deliveryInfo` (данные берутся из полей Store, а не slots-api) |
| Магазин из кеша стал неактивным | Фильтровать по `is_active` при выдаче |
| store.code не найден в нашей БД | Пропустить этот магазин (лог warning) |

### Error handling

| Ошибка | HTTP код | Response |
|--------|----------|----------|
| Нет авторизации (addressId передан) | **401** | `{"error": "unauthorized", "message": "Не авторизован"}` |
| Адрес не найден | 404 | `{"error": "address_not_found", "message": "Адрес не найден"}` |
| Нет координат | 422 | `{"error": "address_missing_coordinates", "message": "У адреса отсутствуют координаты"}` |
| Чужой адрес | 403 | `{"error": "forbidden", "message": "Доступ запрещён"}` |
| slots-api любая ошибка | 200 | `{"hasMore": false, "total": 0, "data": []}` + лог |

### GET /api/v1/stores/{storeId}?addressId=... (детальный)

Существует аналогичный расширенный эндпоинт `GET /v1/stores/{storeId}?addressId={id}` — возвращает **один** магазин с реалистичным `deliveryInfo` для заданного адреса. Те же ошибки 401/403/404(`store_not_available`)/422.

---

## 6. Интеграция с slots-api

### Endpoint

```
GET {SLOTS_API_BASE_URL}/api/v1/slots/shops?latitude={lat}&longitude={lng}
```

### Ответ slots-api (200 OK)

```json
{
  "shops": [
    {
      "id": 1,
      "title": "Магазин на Арбате",
      "address": "ул. Арбат, 10",
      "latitude": "55.7520",
      "longitude": "37.5910",
      "logo_img": "https://cdn.example.com/logo.png",
      "delivery_price": 200,
      "min_order_price": 500
    }
  ]
}
```

### Маппинг полей

| slots-api | Наша система | Примечание |
|-----------|--------------|------------|
| `shop.id` | `store.code` | **Ключ маппинга** |
| `delivery_price` | `deliveryInfo.deliveryPrice` | Цена доставки |
| `min_order_price` | `deliveryInfo.minOrderAmount` | Мин. сумма заказа |

### Конфигурация HTTP Client

```
Connect timeout: 5 сек
Read timeout: 10 сек
Retry: 1 попытка с backoff 1 сек (только при timeout/5xx)
Не ретраить: при 4xx
```

### Конфигурация (config/services.php)

```php
'slots_api' => [
    'base_url' => env('SLOTS_API_BASE_URL'),
    'timeout' => env('SLOTS_API_TIMEOUT', 10),
    'cache_ttl' => env('SLOTS_API_CACHE_TTL', 3600), // 1 час
],
```

### Переменные окружения (.env)

```
SLOTS_API_BASE_URL=https://api.slots.example.com
SLOTS_API_TIMEOUT=10
SLOTS_API_CACHE_TTL=3600
```

---

## 7. Нефункциональные требования

### Кеширование

- **Драйвер:** Redis
- **TTL:** 1 час (3600 сек)
- **Ключ:** `available_stores:address:{address_id}`
- **Инвалидация:** При изменении координат адреса (AddressObserver)

### Производительность

- Cache hit: < 50ms
- Cache miss: < 15 сек (с учётом внешнего запроса)

### Безопасность

- Требуется авторизация (Sanctum)
- Проверка владения адресом

### Логирование

При ошибках slots-api логировать:
- `address_id`
- `coordinates` (lat, lng)
- `error_message`
- `response_code`
- `duration_ms`

---

## 8. Ожидаемый результат

### Файлы для создания

| Файл | Описание |
|------|----------|
| `app/Services/Store/AvailableStoreService.php` | Основной сервис |
| `app/Services/SlotsApi/SlotsApiClient.php` | HTTP client для slots-api |
| `app/Observers/AddressObserver.php` | Инвалидация кеша |
| `tests/Feature/Api/V1/Store/AvailableStoreTest.php` | Feature тесты |
| `tests/Unit/Services/Store/AvailableStoreServiceTest.php` | Unit тесты |

### Файлы для модификации

| Файл | Изменения |
|------|-----------|
| `app/Http/Controllers/Api/V1/StoreController.php` | Добавить параметр addressId |
| `app/Http/Requests/Api/V1/GetStoresRequest.php` | Валидация addressId |
| `app/Http/Resources/Api/V1/StoreResource.php` | Добавить deliveryInfo |
| `config/services.php` | Конфиг slots_api |
| `.env.example` | Новые переменные |
| `app/Providers/AppServiceProvider.php` | Регистрация Observer |

### Сервис AvailableStoreService

```
Методы:
- getAvailableStores(Address $address): Collection<Store>
- getDeliveryInfo(Address $address, Store $store): ?array
- refreshCache(Address $address): void
- invalidateCache(int $addressId): void
```

### Тесты

**Feature тесты:**
- [ ] `GET /api/v1/stores?addressId=1` возвращает только доступные магазины
- [ ] `GET /api/v1/stores` без addressId возвращает все (backward compatible)
- [ ] Чужой адрес → 403
- [ ] Адрес без координат → 422
- [ ] Несуществующий адрес → 404
- [ ] slots-api недоступен → пустой список
- [ ] Кеширование работает (mock slots-api, проверить что второй запрос не вызывает API)
- [ ] Инвалидация кеша при обновлении координат адреса

**Unit тесты:**
- [ ] `SlotsApiClient::getAvailableShops()` — парсинг ответа
- [ ] `SlotsApiClient` — обработка ошибок (timeout, 500, 4xx)
- [ ] `AvailableStoreService::getAvailableStores()` — маппинг по code
- [ ] Фильтрация неактивных магазинов

---

## 9. Чек-лист для code review

- [ ] Проверка авторизации и владения адресом
- [ ] Валидация наличия координат у адреса
- [ ] Timeout и retry для внешнего API
- [ ] При ошибке slots-api → пустой список (не exception)
- [ ] Кеширование с TTL 1 час
- [ ] Инвалидация кеша при изменении координат (AddressObserver)
- [ ] Маппинг по `store.code`
- [ ] Backward compatibility (без addressId работает как раньше)
- [ ] Логирование ошибок внешнего API
- [ ] Feature и Unit тесты написаны
- [ ] Конфигурация через env
- [ ] `.env.example` обновлён
