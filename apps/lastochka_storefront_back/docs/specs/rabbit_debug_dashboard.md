# ТЗ: RabbitMQ Debug Dashboard

## 1. Цель

Веб-интерфейс для мониторинга RabbitMQ обмена:
- Просмотр входящих сообщений и что записалось в БД
- Просмотр исходящих сообщений
- Просмотр ошибок обработки

**Измеримый результат:** тестировщик видит в браузере весь путь сообщения — payload из RabbitMQ → результат в БД.

---

## 2. Контекст

### Где живёт
- Модуль `App\RabbitMQ\Debug`
- Роуты `/api/debug/rabbit/*` — API для данных
- Роут `/debug/rabbit` — веб-интерфейс (Blade)

### Ограничение доступа
- Доступно только при `APP_DEBUG=true` или с ключом `RABBIT_DEBUG_KEY`
- На production **отключено по умолчанию**

### Что логируем
- **Все входящие сообщения** — с payload и результатом (что записалось в БД)
- **Все исходящие сообщения** — с payload
- **Ошибки** — с полным stack trace

---

## 3. Данные

### 3.1. Таблица `rabbit_message_log`

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | bigint PK | - |
| `direction` | enum('inbound', 'outbound') | Направление |
| `routing_key` | varchar(255) | Routing key |
| `queue_name` | varchar(255) nullable | Очередь (для inbound) |
| `payload` | json | Полный payload сообщения |
| `entity_type` | varchar(50) nullable | Тип сущности (products, orders) |
| `entity_id` | bigint unsigned nullable | ID записи в БД |
| `external_id` | varchar(255) nullable | External ID из сообщения |
| `status` | enum('processed', 'failed') | Статус |
| `error_message` | text nullable | Текст ошибки |
| `error_trace` | text nullable | Stack trace (только при failed) |
| `db_snapshot` | json nullable | Состояние записи в БД после обработки |
| `processing_time_ms` | int unsigned nullable | Время обработки |
| `created_at` | timestamp | - |

**Индексы:**
- `(direction, created_at DESC)` — основной список
- `(status, created_at DESC)` — фильтр ошибок
- `(entity_type, entity_id)` — поиск по сущности
- `(routing_key)` — фильтр по типу события

### 3.2. Структура `db_snapshot`

Снимок записи из БД после обработки:

```json
{
  "table": "products",
  "id": 123,
  "data": {
    "name": "Молоко 3.2%",
    "price": "89.90",
    "quantity": 50,
    "category_id": 5,
    "store_id": 1,
    "updated_at": "2024-01-15T10:30:00.000Z"
  }
}
```

---

## 4. Логика

### 4.1. Логирование входящих (inbound)

**Точка интеграции:** Обёртка вокруг трансформера в consumer.

```
Сообщение из RabbitMQ
    ↓
[Создать запись в логе]
    ↓
Трансформер обрабатывает
    ↓
[Обновить запись: entity_id, db_snapshot, status]
```

**Шаги:**
1. Получили сообщение → записать `routing_key`, `payload`, `queue_name`
2. Обработали успешно → добавить `entity_id`, `db_snapshot` (fetch записи из БД), `status=processed`
3. Ошибка → добавить `error_message`, `error_trace`, `status=failed`

**db_snapshot:** После успешной обработки делаем `Model::find($entityId)->toArray()` и сохраняем.

### 4.2. Логирование исходящих (outbound)

**Точка интеграции:** После записи в outbox или в `CustomEventPublisher`.

**Шаги:**
1. Публикуем сообщение → записать `routing_key`, `payload`, `entity_type`, `entity_id`
2. Статус сразу `processed` (ошибки outbox — отдельная тема)

### 4.3. API Endpoints

| Method | Path | Описание |
|--------|------|----------|
| GET | `/api/debug/rabbit/messages` | Список сообщений |
| GET | `/api/debug/rabbit/messages/{id}` | Детали сообщения |
| GET | `/api/debug/rabbit/stats` | Статистика |
| DELETE | `/api/debug/rabbit/messages` | Очистка логов |

### 4.4. Фильтры для GET /messages

| Параметр | Тип | Пример |
|----------|-----|--------|
| `direction` | enum | `inbound`, `outbound` |
| `status` | enum | `processed`, `failed` |
| `routing_key` | string | `product.created`, `product.*` |
| `entity_type` | string | `products`, `orders` |
| `date_from` | datetime | `2024-01-15T00:00:00Z` |
| `date_to` | datetime | `2024-01-15T23:59:59Z` |
| `per_page` | int | 50 (default), max 100 |

### 4.5. GET /messages Response

```json
{
  "data": [
    {
      "id": 123,
      "direction": "inbound",
      "routing_key": "product.created",
      "entity_type": "products",
      "entity_id": 456,
      "external_id": "ext_789",
      "status": "processed",
      "processing_time_ms": 45,
      "created_at": "2024-01-15T10:30:00.000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 10,
    "per_page": 50,
    "total": 500
  }
}
```

### 4.6. GET /messages/{id} Response

```json
{
  "id": 123,
  "direction": "inbound",
  "routing_key": "product.created",
  "queue_name": "storefront_products",
  "payload": {
    "id": "ext_789",
    "name": "Молоко 3.2%",
    "price": 89.90,
    "shop_code": "main"
  },
  "entity_type": "products",
  "entity_id": 456,
  "external_id": "ext_789",
  "status": "processed",
  "error_message": null,
  "error_trace": null,
  "db_snapshot": {
    "table": "products",
    "id": 456,
    "data": {
      "name": "Молоко 3.2%",
      "price": "89.90",
      "quantity": 50,
      "store_id": 1
    }
  },
  "processing_time_ms": 45,
  "created_at": "2024-01-15T10:30:00.000Z"
}
```

### 4.7. GET /stats Response

```json
{
  "period": "24h",
  "inbound": {
    "total": 1250,
    "processed": 1245,
    "failed": 5
  },
  "outbound": {
    "total": 340,
    "processed": 340,
    "failed": 0
  },
  "by_routing_key": {
    "product.created": 500,
    "product.updated": 400,
    "order.status.changed": 200,
    "customer.created": 150
  },
  "avg_processing_time_ms": 32
}
```

---

## 5. Веб-интерфейс

### 5.1. Страница `/debug/rabbit`

**Технология:** Blade + Alpine.js (или чистый JS). Без сборки — один файл.

### 5.2. Макет интерфейса

```
┌─────────────────────────────────────────────────────────────────┐
│  RabbitMQ Debug Dashboard                          [Очистить]   │
├─────────────────────────────────────────────────────────────────┤
│  Фильтры:                                                       │
│  [Направление ▼] [Статус ▼] [Routing key ___] [Дата с/по]      │
├─────────────────────────────────────────────────────────────────┤
│  Статистика за 24ч:                                             │
│  Входящих: 1250 (5 ошибок)  │  Исходящих: 340  │  Avg: 32ms    │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌─ Список сообщений ─────────────────────────────────────────┐ │
│  │ ● 10:30:15  product.created     → Product #456    32ms     │ │
│  │ ● 10:30:14  category.updated    → Category #12    18ms     │ │
│  │ ✗ 10:30:12  product.created     FAILED            -        │ │
│  │ ◀ 10:29:58  order.order_created ← Order #89       -        │ │
│  │ ● 10:29:45  product.updated     → Product #123    25ms     │ │
│  └─────────────────────────────────────────────────────────────┘ │
│                                                                 │
│  ┌─ Детали сообщения (клик по строке) ────────────────────────┐ │
│  │                                                             │ │
│  │  Routing Key: product.created                               │ │
│  │  Статус: ● Processed (32ms)                                 │ │
│  │  Entity: Product #456                                       │ │
│  │                                                             │ │
│  │  ┌─ Payload (что пришло) ─┐  ┌─ DB Snapshot (что в БД) ──┐ │ │
│  │  │ {                      │  │ {                          │ │ │
│  │  │   "id": "ext_789",     │  │   "id": 456,               │ │ │
│  │  │   "name": "Молоко",    │  │   "name": "Молоко 3.2%",   │ │ │
│  │  │   "price": 89.90       │  │   "price": "89.90",        │ │ │
│  │  │ }                      │  │   "store_id": 1            │ │ │
│  │  └────────────────────────┘  └────────────────────────────┘ │ │
│  │                                                             │ │
│  └─────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────┘
```

### 5.3. Элементы UI

**Список:**
- Иконки направления: `●` inbound (зелёный), `◀` outbound (синий), `✗` failed (красный)
- Время в локальном формате
- Routing key
- Entity type + ID (ссылка?)
- Время обработки

**Детали (при клике):**
- Два блока JSON рядом: Payload | DB Snapshot
- Подсветка синтаксиса (можно highlight.js)
- Для ошибок: красный блок с error_message + раскрываемый stack trace

**Фильтры:**
- Dropdown для direction, status
- Текстовое поле для routing_key (с autocomplete по существующим)
- Date picker для периода

---

## 6. Нефункциональные требования

### 6.1. Безопасность

```php
// Middleware
if (!config('rabbit-debug.enabled')) {
    abort(404);
}

if (app()->isProduction() && !$this->hasValidApiKey($request)) {
    abort(403);
}
```

**Hidden fields:** Не логировать значения полей из списка (password, token, secret) — заменять на `[HIDDEN]`.

### 6.2. Производительность

- Логирование через queue job (async) чтобы не блокировать consumer
- Retention: автоочистка через scheduled command `rabbit:cleanup-logs`
- Default retention: 7 дней

### 6.3. Конфигурация

**config/rabbit-debug.php:**
```php
return [
    'enabled' => env('RABBIT_DEBUG_ENABLED', false),
    'api_key' => env('RABBIT_DEBUG_KEY'),
    'retention_days' => env('RABBIT_DEBUG_RETENTION', 7),
    'hidden_fields' => ['password', 'token', 'secret', 'api_key'],
    'async_logging' => env('RABBIT_DEBUG_ASYNC', true),
];
```

---

## 7. Ожидаемый результат

### Файлы

```
app/
├── RabbitMQ/
│   └── Debug/
│       ├── RabbitDebugService.php      # Сервис логирования
│       ├── RabbitMessageLog.php        # Модель
│       └── Jobs/
│           └── LogRabbitMessage.php    # Async job для записи
├── Http/
│   └── Controllers/
│       └── Debug/
│           └── RabbitDebugController.php
config/
└── rabbit-debug.php

database/
└── migrations/
    └── xxxx_create_rabbit_message_log_table.php

resources/
└── views/
    └── debug/
        └── rabbit.blade.php            # Веб-интерфейс

routes/
└── (добавить в web.php и api.php)
```

### Интеграция с существующим кодом

Добавить вызовы `RabbitDebugService` в:
1. Базовый consumer (или trait для всех трансформеров)
2. `CustomEventPublisher::dispatch()`
3. Outbox processor (если есть)

### Тесты

- `RabbitDebugServiceTest` — логирование inbound/outbound, скрытие полей
- `RabbitDebugControllerTest` — API endpoints, фильтры
- `RabbitDebugAccessTest` — middleware, защита

### Команды

```bash
# Очистка старых логов (добавить в scheduler)
php artisan rabbit:cleanup-logs --days=7
```

---

## 8. Примеры использования

### Сценарий 1: Проверить, что товар пришёл и записался

1. Открыть `/debug/rabbit`
2. Фильтр: routing_key = `product.created`
3. Найти нужное сообщение по времени
4. Кликнуть → увидеть payload слева, запись в БД справа
5. Сравнить: цена, название, категория — всё корректно

### Сценарий 2: Найти ошибку обработки

1. Фильтр: status = `failed`
2. Увидеть красные строки с ошибками
3. Кликнуть → увидеть payload + error message + stack trace
4. Понять причину (невалидные данные, отсутствует связь и т.д.)

### Сценарий 3: Проверить, что заказ отправился

1. Фильтр: direction = `outbound`, routing_key = `order.*`
2. Найти сообщение по времени создания заказа
3. Проверить payload — все данные на месте
