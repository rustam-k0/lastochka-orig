# ТЗ: Интеграция заказов через RabbitMQ → 1С

## 1. Цель

Реализовать приём заказов из магазина Aladdin через RabbitMQ и их передачу в 1С по существующему протоколу CommerceML (pull-модель, 1С забирает по расписанию).

**Измеримый результат:** Заказы, созданные в магазине, автоматически появляются в 1С при очередном цикле синхронизации (каждые 10 минут).

---

## 2. Контекст

### Где живёт
- **Сервис:** `alladin_1c_parser` (агрегатор/мост между магазином и 1С)
- **Модуль:** Новый consumer для RabbitMQ + адаптация существующего `OrderExportHandler`

### Текущая архитектура

```
┌─────────────┐     CommerceML      ┌─────────────────────┐     CommerceML     ┌─────────┐
│   Магазин   │ ─────────────────── │  alladin_1c_parser  │ ◄───────────────── │   1С    │
│   Aladdin   │     (RabbitMQ)      │     (этот сервис)   │   (1С забирает)    │         │
└─────────────┘                     └─────────────────────┘                    └─────────┘
     │                                       │                                      │
     │  Product/Category sync ───────────────┼──────────────────────────────────────┤
     │  (source: 1c → shop)                  │                                      │
     │                                       │                                      │
     │  Orders sync (НОВОЕ) ─────────────────┤                                      │
     │  (source: shop → 1c)                  │                                      │
     └───────────────────────────────────────┴──────────────────────────────────────┘
```

### Триггер процесса
1. **Событие:** Создание/обновление заказа в магазине Aladdin
2. **Публикация:** Магазин отправляет событие в RabbitMQ (exchange: `app_events`, routing key: `orders.created`)
3. **Потребление:** Consumer в `alladin_1c_parser` слушает `orders.created`, сохраняет заказ в локальную БД
4. **Выгрузка:** 1С по расписанию запрашивает заказы через `/1c-exchange?type=sale`

### Связь с текущей системой
- **Используем:** Существующий `OrderExportHandler` для формирования XML
- **Используем:** Существующие модели `Order`, `OrderItem`
- **Добавляем:** RabbitMQ consumer для приёма заказов

---

## 3. Данные

### 3.1 Входные данные (из RabbitMQ)

**Формат сообщения:**
```json
{
  "event": "orders.created",
  "source": "aladdin_shop",
  "shop_code": "aladdin",
  "timestamp": "2025-01-09T15:30:00+03:00",
  "payload": {
    "id": 12345,
    "number": "202501090001",
    "status": "new",
    "customer": {
      "name": "Иван Иванов",
      "phone": "+79001234567",
      "email": "ivan@example.com"
    },
    "delivery_address": "г. Москва, ул. Примерная, д. 1",
    "comment": "Позвонить за час до доставки",
    "total": 15000.00,
    "items": [
      {
        "external_id": "UUID-товара-из-1С",
        "name": "Товар 1",
        "quantity": 2,
        "price": 5000.00,
        "total": 10000.00
      },
      {
        "external_id": "UUID-товара-из-1С-2",
        "name": "Товар 2",
        "quantity": 1,
        "price": 5000.00,
        "total": 5000.00
      }
    ]
  }
}
```

**Валидация:**
- `payload.id` — обязательно, уникальный ID из магазина
- `payload.number` — обязательно, номер заказа
- `payload.items` — минимум 1 позиция
- `payload.items[].external_id` — обязательно, должен существовать в таблице `products`
- `payload.customer.phone` или `payload.customer.email` — хотя бы одно обязательно

### 3.2 Сущности

**Таблица `orders` (существует):**
| Поле | Тип | Описание |
|------|-----|----------|
| id | bigint | PK |
| external_id | string, nullable | ID из магазина (новое назначение: `shop_order_id`) |
| number | string | Номер заказа |
| status | enum | new, exported, confirmed, cancelled |
| customer_name | string | ФИО покупателя |
| customer_phone | string, nullable | Телефон |
| customer_email | string, nullable | Email |
| delivery_address | text, nullable | Адрес доставки |
| comment | text, nullable | Комментарий |
| total | decimal(12,2) | Сумма заказа |
| exported_at | timestamp, nullable | Когда выгружен в 1С |
| one_c_response | json, nullable | Ответ от 1С |
| created_at | timestamp | |
| updated_at | timestamp | |

**Предлагаемые изменения:**
| Новое поле | Тип | Описание |
|------------|-----|----------|
| shop_order_id | bigint, unique | ID заказа из магазина (для идемпотентности) |
| source | string, default 'shop' | Источник заказа |
| received_at | timestamp | Когда получен из RabbitMQ |

**Таблица `order_items` (существует, без изменений):**
| Поле | Тип | Описание |
|------|-----|----------|
| id | bigint | PK |
| order_id | bigint | FK → orders |
| product_id | bigint, nullable | FK → products |
| external_id | string | ID товара из 1С |
| name | string | Наименование |
| quantity | decimal(10,3) | Количество |
| price | decimal(12,2) | Цена за единицу |
| total | decimal(12,2) | Сумма |

### 3.3 Выходные данные

**Для 1С (XML CommerceML)** — формируется существующим `OrderExportHandler`:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<КоммерческаяИнформация ВерсияСхемы="2.10" ДатаФормирования="2025-01-09T15:30:00">
  <Документ>
    <Ид>order-123</Ид>
    <Номер>202501090001</Номер>
    <!-- ... остальные поля ... -->
  </Документ>
</КоммерческаяИнформация>
```

---

## 4. Логика

### 4.1 Happy Path: Приём заказа из RabbitMQ

```
1. Consumer получает сообщение `orders.created` из очереди `orders_to_1c`
2. Валидация payload (JSON Schema или FormRequest-подобная)
3. Проверка идемпотентности: SELECT * FROM orders WHERE shop_order_id = :id
   → Если существует: логируем "duplicate", ACK сообщения, выход
4. Проверка товаров: все external_id должны существовать в products
   → Если не найден: логируем warning, но НЕ блокируем (товар мог быть удалён)
5. DB::transaction:
   - Создать Order (status = 'new', source = 'shop')
   - Создать OrderItems
6. ACK сообщения
7. Логирование успеха
```

### 4.2 Happy Path: Выгрузка в 1С

```
1. 1С делает запрос GET /1c-exchange?type=sale&mode=query
2. OrderExportHandler::getXML() выбирает заказы:
   - status = 'new'
   - exported_at IS NULL
3. Формирует XML
4. 1С подтверждает получение (mode=success)
5. OrderExportHandler::stepSuccess() помечает заказы как exported
```

**Без изменений** — текущая логика уже работает.

### 4.3 Edge Cases

| Ситуация | Решение |
|----------|---------|
| Дубликат заказа (повторное сообщение) | Проверка по `shop_order_id`, ACK без обработки |
| Товар не найден в БД | Создать OrderItem без `product_id`, использовать `external_id` |
| Невалидный JSON | NACK + dead letter queue |
| Ошибка БД | NACK + retry (3 попытки), затем dead letter |
| Заказ обновлён в магазине | Пока не поддерживается (только created) |
| Заказ отменён в магазине | Пока не поддерживается (только created) |

### 4.4 Error Handling

| Ошибка | Действие | Retry |
|--------|----------|-------|
| Validation failed | Log error, NACK → DLQ | Нет |
| DB connection failed | NACK, сообщение вернётся в очередь | Да, автоматически |
| Duplicate order | Log info, ACK | Нет |
| Unknown event type | Log warning, ACK | Нет |

---

## 5. Интеграции

### 5.1 RabbitMQ (Consumer)

**Конфигурация:**
```php
// config/rabbitmq-events.php (добавить секцию consumers)
'consumers' => [
    'orders' => [
        'enabled' => env('RABBITMQ_CONSUME_ORDERS', true),
        'queue' => 'orders_to_1c',  // локальная очередь для этого consumer
        'exchange' => 'app_events',
        'routing_keys' => ['orders.created'],  // слушаем то, что публикует магазин
        'handler' => \App\RabbitMQ\Consumers\OrderConsumer::class,
        'prefetch_count' => 10,
        'retry' => [
            'max_attempts' => 3,
            'delay_ms' => 5000,
        ],
        'dead_letter' => [
            'exchange' => 'app_events.dlx',
            'queue' => 'orders_to_1c.dlq',
        ],
    ],
],
```

**Что нужно от RabbitMQ:**
- Exchange: `app_events` (topic, durable) — уже существует
- Queue: `orders_to_1c` — создать (локальная очередь consumer'а)
- Binding: `orders.created` → `orders_to_1c`
- Dead Letter Exchange: `app_events.dlx`

**Auth:** Использовать существующие credentials из `.env`

### 5.2 Магазин Aladdin (Publisher)

**Требования к магазину:**
- Публиковать события при создании заказа
- Routing key: `orders.created`
- Формат payload: см. секцию 3.1
- Гарантия: at-least-once delivery

### 5.3 1С (Pull)

**Без изменений.** 1С продолжает забирать заказы по расписанию через:
- `GET /1c-exchange?type=sale&mode=query` → получить XML
- `GET /1c-exchange?type=sale&mode=success` → подтвердить

---

## 6. Нефункциональные требования

### 6.1 Транзакции
- Создание Order + OrderItems — **атомарно** (DB::transaction)
- ACK сообщения — **после** успешного commit

### 6.2 Идемпотентность
- **Обязательно.** Consumer может получить одно сообщение несколько раз
- Ключ идемпотентности: `shop_order_id` (уникальный индекс)

### 6.3 Асинхронность
- Consumer работает как отдельный процесс (`php artisan rabbitmq:consume orders`)
- Supervisor для перезапуска при падении

### 6.4 Производительность
- **Ожидаемая нагрузка:** до 1000 заказов/день
- **Latency:** от создания в магазине до появления в БД — < 5 секунд
- **Consumer prefetch:** 10 сообщений

### 6.5 Мониторинг
- Логирование: `storage/logs/rabbitmq-orders.log`
- Метрики (опционально): количество принятых/отклонённых заказов
- Alert: если DLQ > 10 сообщений

---

## 7. Ожидаемый результат

### 7.1 Код

| Компонент | Путь | Описание |
|-----------|------|----------|
| Consumer | `app/RabbitMQ/Consumers/OrderConsumer.php` | Обработчик сообщений |
| DTO | `app/RabbitMQ/DTO/OrderEventDTO.php` | Data Transfer Object |
| Service | `app/Services/OrderImportService.php` | Бизнес-логика импорта |
| Command | `app/Console/Commands/ConsumeOrdersCommand.php` | Artisan команда |
| Config | `config/rabbitmq-events.php` | Секция consumers |

### 7.2 Миграции

```php
// Добавить поля в orders
Schema::table('orders', function (Blueprint $table) {
    $table->unsignedBigInteger('shop_order_id')->nullable()->unique()->after('id');
    $table->string('source')->default('manual')->after('status');
    $table->timestamp('received_at')->nullable()->after('exported_at');
    
    $table->index(['status', 'exported_at']); // для выборки на экспорт
});
```

### 7.3 Тесты

| Тип | Покрытие |
|-----|----------|
| Unit | OrderConsumer, OrderImportService |
| Feature | Полный цикл: RabbitMQ → DB → XML export |
| Integration | Mock RabbitMQ, проверка идемпотентности |

### 7.4 Документация

- Обновить `docs/INTEGRATION-README.md` — добавить секцию про заказы
- Описать формат событий для команды магазина

### 7.5 Deployment

1. Запустить миграцию
2. Создать очередь в RabbitMQ (или auto-declare)
3. Добавить supervisor config для consumer:
```ini
[program:orders-consumer]
command=php /path/to/artisan rabbitmq:consume orders
autostart=true
autorestart=true
stderr_logfile=/var/log/orders-consumer.err.log
stdout_logfile=/var/log/orders-consumer.out.log
```
4. Включить публикацию событий в магазине

---

## 8. Диаграмма последовательности

```
┌─────────┐     ┌──────────┐     ┌──────────────┐     ┌────────────┐     ┌─────┐
│ Магазин │     │ RabbitMQ │     │ OrderConsumer│     │ OrderExport│     │ 1С  │
└────┬────┘     └────┬─────┘     └──────┬───────┘     └─────┬──────┘     └──┬──┘
     │               │                  │                   │               │
     │ order.created │                  │                   │               │
     │──────────────>│                  │                   │               │
     │               │                  │                   │               │
     │               │ deliver message  │                   │               │
     │               │─────────────────>│                   │               │
     │               │                  │                   │               │
     │               │                  │ validate + save   │               │
     │               │                  │──────────────────>│               │
     │               │                  │      (DB)         │               │
     │               │                  │                   │               │
     │               │      ACK         │                   │               │
     │               │<─────────────────│                   │               │
     │               │                  │                   │               │
     │               │                  │                   │  GET /sale    │
     │               │                  │                   │<──────────────│
     │               │                  │                   │               │
     │               │                  │                   │  XML orders   │
     │               │                  │                   │──────────────>│
     │               │                  │                   │               │
     │               │                  │                   │  success      │
     │               │                  │                   │<──────────────│
     │               │                  │                   │               │
     │               │                  │                   │ mark exported │
     │               │                  │                   │───────────────│
     │               │                  │                   │               │
```

---

## 9. Риски и митигация

| Риск | Вероятность | Влияние | Митигация |
|------|-------------|---------|-----------|
| Потеря сообщений | Низкая | Высокое | Durable queue, persistent messages, at-least-once |
| Дублирование заказов в 1С | Средняя | Среднее | Уникальный `shop_order_id`, проверка в 1С по номеру |
| Consumer падает | Средняя | Среднее | Supervisor, автоперезапуск |
| Рассинхрон товаров | Средняя | Низкое | Использовать `external_id`, не блокировать заказ |
| 1С не забирает заказы | Низкая | Высокое | Мониторинг, alert если заказы висят > 1 часа |

---

## 10. Открытые вопросы

1. **Обновление заказов:** Нужно ли обновлять заказ, если он уже `exported`? Или только `new`?
2. **Отмена заказов:** Как 1С узнает об отмене? Отдельный статус в XML или не выгружать?
3. **Обратная связь:** Нужно ли отправлять статус из 1С обратно в магазин?
4. **Контрагенты:** Создавать нового контрагента в 1С или использовать "Розничный покупатель"?

---

*Версия: 1.0*  
*Дата: 2025-01-09*
