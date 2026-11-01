# Интеграция 1С ↔ Laravel

## Обзор

Реализована двусторонняя синхронизация между 1С:Торговое предприятие 7 и Laravel-сайтом по протоколу CommerceML 2.x.

## Структура файлов

```
app/
├── Console/Commands/
│   ├── Import1CCommand.php        # php artisan 1c:import
│   ├── ExportOrders1CCommand.php  # php artisan 1c:export-orders
│   ├── Cleanup1CCommand.php       # php artisan 1c:cleanup
│   └── Ping1CCommand.php          # php artisan 1c:ping
├── Jobs/
│   ├── ImportCatalogJob.php       # Асинхронный импорт каталога
│   ├── ImportOffersJob.php        # Асинхронный импорт остатков/цен
│   └── ProcessImagesJob.php       # Обработка изображений
├── Models/
│   ├── Category.php
│   ├── Product.php
│   ├── Price.php
│   ├── Stock.php
│   ├── Image.php
│   ├── Order.php
│   ├── OrderItem.php
│   └── ExchangeLog.php
└── Services/Exchange1C/
    ├── CatalogImportHandler.php   # Handler для пакета mavsan
    ├── OrderExportHandler.php     # Handler экспорта заказов
    ├── CatalogImporter.php        # Сервис импорта каталога
    └── OffersImporter.php         # Сервис импорта остатков/цен

config/
├── protocolExchange1C.php         # Конфиг пакета mavsan
├── exchange1c.php                 # Дополнительные настройки
└── logging.php                    # Канал 1c-exchange

routes/
└── console.php                    # Scheduler
```

## Установка

### 1. Миграции

```bash
php artisan migrate
```

### 2. Создание пользователя для обмена

```bash
php artisan db:seed --class=Exchange1CUserSeeder
```

Или вручную создайте пользователя с email из `.env` (`1C_EXCHANGE_LOGIN`).

### 3. Настройка .env

```env
# 1C Exchange Configuration
1C_SERVER_URL=http://192.168.70.254/tp7
1C_EXCHANGE_LOGIN=1c_exchange
1C_EXCHANGE_PASSWORD=your_secure_password
```

### 4. Настройка очередей

```bash
# Запуск worker
php artisan queue:work --queue=default --tries=3

# Или через Horizon (если установлен)
php artisan horizon
```

### 5. Настройка Scheduler (cron)

Добавить в crontab:

```bash
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

## Endpoint обмена

| Параметр | Значение |
|----------|----------|
| URL | `https://your-site.com/1c-exchange` |
| Методы | GET, POST |
| Авторизация | Basic Auth |

## Artisan-команды

```bash
# Ручной импорт каталога
php artisan 1c:import --type=catalog

# Ручной импорт остатков/цен
php artisan 1c:import --type=offers

# Импорт из конкретного файла
php artisan 1c:import --type=catalog --file=/path/to/import.xml

# Экспорт заказов
php artisan 1c:export-orders

# Экспорт без пометки заказов (dry-run)
php artisan 1c:export-orders --dry-run

# Очистка старых файлов
php artisan 1c:cleanup --days=7

# Проверка связи с 1С
php artisan 1c:ping
```

## Scheduler

| Задача | Частота |
|--------|---------|
| Импорт каталога | Каждые 4 часа |
| Синхронизация остатков/цен | Каждые 15 минут |
| Экспорт заказов | Каждые 10 минут |
| Очистка старых файлов | Ежедневно в 3:00 |

## Логирование

Логи обмена: `storage/logs/1c-exchange-*.log`

```php
Log::channel('1c-exchange')->info('Message');
```

## Настройка в 1С

1. **Администрирование → Обмен данными → Узлы обмена с сайтами**
2. Создать новый узел:
   - Адрес сайта: `https://your-site.com`
   - Каталог на сайте: `/1c-exchange`
   - Логин: значение из `1C_EXCHANGE_LOGIN`
   - Пароль: значение из `1C_EXCHANGE_PASSWORD`
3. Настроить расписание выгрузки

## Модели данных

### Category
- `external_id` — GUID из 1С
- `parent_id` — иерархия
- `name`, `sort`, `is_active`

### Product
- `external_id` — GUID из 1С
- `category_id` — связь с категорией
- `sku` — артикул
- `attributes` — JSON с характеристиками

### Price
- `product_id`, `price_type`, `value`, `currency`
- Unique: (product_id, price_type)

### Stock
- `product_id`, `warehouse_id`, `quantity`
- Unique: (product_id, warehouse_id)

### Order
- Статусы: `new`, `exported`, `confirmed`, `cancelled`
- `exported_at` — время выгрузки в 1С

## Примеры использования

### Получить товары в наличии

```php
$products = Product::active()
    ->inStock()
    ->with(['category', 'prices', 'stocks', 'mainImage'])
    ->get();
```

### Создать заказ

```php
$order = Order::create([
    'number' => Order::generateNumber(),
    'customer_name' => 'Иванов Иван',
    'customer_phone' => '+7 999 123-45-67',
    'customer_email' => 'ivan@example.com',
    'delivery_address' => 'г. Москва, ул. Примерная, д. 1',
]);

$order->items()->create([
    'product_id' => $product->id,
    'external_id' => $product->external_id,
    'name' => $product->name,
    'quantity' => 2,
    'price' => 1000.00,
]);

$order->recalculateTotal();
```

### Получить историю обменов

```php
$logs = ExchangeLog::latest()
    ->take(10)
    ->get();
```

## Troubleshooting

### 1С не может подключиться

- Проверьте URL: `php artisan 1c:ping`
- Проверьте логин/пароль пользователя
- Убедитесь, что middleware `StartSession` включен
- Проверьте логи: `storage/logs/1c-exchange-*.log`

### Не импортируются товары

- Проверьте формат XML (UTF-8 или windows-1251)
- Смотрите логи очередей
- Попробуйте ручной импорт: `php artisan 1c:import --type=catalog`

### Заказы не экспортируются

- Убедитесь, что заказы имеют статус `new`
- Проверьте `exported_at IS NULL`
- Запустите: `php artisan 1c:export-orders --dry-run`

---

## Интеграция заказов: Магазин → RabbitMQ → 1С

### Архитектура

```
Магазин Aladdin → RabbitMQ → alladin_1c_parser → 1С (pull)
```

1. Магазин публикует событие `orders.created` в RabbitMQ
2. Consumer сохраняет заказ в БД
3. 1С забирает заказы по расписанию через `/1c-exchange?type=sale`

### Запуск consumer

```bash
# Однократный запуск
php artisan rabbitmq:consume-orders

# Обработать одно сообщение и выйти (для тестов)
php artisan rabbitmq:consume-orders --once

# С таймаутом
php artisan rabbitmq:consume-orders --timeout=60
```

### Supervisor конфигурация

```ini
[program:orders-consumer]
command=php /path/to/artisan rabbitmq:consume-orders
directory=/path/to/project
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/orders-consumer.log
```

### Формат события из магазина

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
    "comment": "Позвонить за час",
    "total": 15000.00,
    "items": [
      {
        "external_id": "UUID-товара-из-1С",
        "name": "Товар 1",
        "quantity": 2,
        "price": 5000.00,
        "total": 10000.00
      }
    ]
  }
}
```

### Логирование

Логи consumer: `storage/logs/rabbitmq-orders-*.log`

```php
Log::channel('rabbitmq-orders')->info('Message');
```

### RabbitMQ инфраструктура

| Компонент | Имя |
|-----------|-----|
| Exchange | `app_events` (topic) |
| Queue | `orders_to_1c` |
| Routing Key | `orders.created` |
| DLQ | `orders_to_1c.dlq` |

### Переменные окружения

```env
RABBITMQ_HOST=localhost
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/
RABBITMQ_EXCHANGE=app_events
RABBITMQ_CONSUME_ORDERS=true
```

### Troubleshooting

**Consumer не запускается:**
- Проверьте подключение к RabbitMQ
- Убедитесь, что exchange `app_events` существует
- Проверьте логи: `storage/logs/rabbitmq-orders-*.log`

**Дубликаты заказов:**
- Проверяется по `shop_order_id` (идемпотентность)
- Дубликаты игнорируются, логируется info

**Сообщения в DLQ:**
- Проверьте формат JSON
- Убедитесь, что все обязательные поля присутствуют
- Смотрите логи с ошибками валидации
