# ТЗ: Интеграция RabbitMQ

## 1. Цель

Реализовать двусторонний обмен данными с внешними системами через RabbitMQ.

## 2. Контекст

- **Модуль:** Integration / Messaging
- **Роль системы:** Producer (отправка) + Consumer (получение)
- **Внешние системы:** RetailBridge → RetailCRM, Bagisto, DeliverySystem

## 3. Архитектура

### 3.1 Exchange и очереди

```
                        RabbitMQ
                           │
       ┌───────────────────┼───────────────────┐
       │                   │                   │
   ┌───┴───┐           ┌───┴───┐           ┌───┴───┐
   │ topic │           │ topic │           │ topic │
   │exchange│          │exchange│          │exchange│
   │customer│          │product │          │ order │
   └───┬───┘           └───┬───┘           └───┬───┘
       │                   │                   │
  ┌────┴────┐         ┌────┴────┐         ┌────┴────┐
  │queue:   │         │queue:   │         │queue:   │
  │storefront│        │storefront│        │storefront│
  │.customer│         │.product │         │.order  │
  └─────────┘         └─────────┘         └─────────┘
```

### 3.2 Naming Convention

**Exchanges (topic):**
- `customer` — события клиентов
- `product` — события товаров
- `category` — события категорий
- `order` — события заказов
- `shop` — события магазинов
- `user` — события сотрудников (не используется в storefront)

**Routing Keys:**
- `{entity}.created` — создание
- `{entity}.updated` — обновление
- `{entity}.deleted` — удаление
- `{entity}.{specific}` — специфичные события

**Queues:**
- `storefront.{entity}` — очереди для нашего сервиса

## 4. События (Outgoing)

### 4.1 Customer Events

**customer.created**
```json
{
  "id": "1",
  "shop_code": "STOREFRONT",
  "source": "storefront",
  "phone": "+79991234567",
  "name": "Иван",
  "surname": "Иванов",
  "patronymic": null,
  "email": "ivan@example.com",
  "birthday": "1990-01-15",
  "gender": "male",
  "created_at": "2024-01-15T10:30:00Z"
}
```

**customer.updated** — аналогичная структура

**customer.address.created**
```json
{
  "id": "1",
  "shop_code": "STOREFRONT",
  "source": "storefront",
  "client_id": "1",
  "client_source": "storefront",
  "title": "Дом",
  "address": "Россия, Москва, ул. Ленина, д. 10",
  "entrance": "2",
  "intercom": "42",
  "floor": "5",
  "flat": "42",
  "latitude": 55.7558,
  "longitude": 37.6173,
  "active": true,
  "created_at": "2024-01-15T10:30:00Z"
}
```

**customer.address.updated / customer.address.deleted** — аналогично

### 4.2 Order Events

**order.created**
```json
{
  "id": "123",
  "shop_code": "MSK_001",
  "source": "storefront",
  "client_id": "1",
  "client_source": "storefront",
  "client_info": {
    "name": "Иван",
    "surname": "Иванов",
    "phone": "+79991234567",
    "email": "ivan@example.com"
  },
  "items": [
    {
      "id": "1",
      "source": "storefront",
      "product_id": "prod_123",
      "product_source": "bagisto",
      "product_name": "Молоко 3.2%",
      "unit_price": 89,
      "unit_price_effective": 89,
      "line_subtotal": 178,
      "line_discount_total": 0,
      "line_total": 178,
      "quantity": 2
    }
  ],
  "status": "created",
  "items_subtotal": 500,
  "discount_total": 50,
  "items_total_after_discount": 450,
  "shipping_total": 200,
  "grand_total": 650,
  "is_paid": false,
  "payment_type": "card",
  "slot_start_date": "2024-01-15T10:00:00Z",
  "slot_end_date": "2024-01-15T12:00:00Z",
  "shipping_method": "courier",
  "address": {...},
  "created_at": "2024-01-15T10:30:00Z"
}
```

**order.paid**
```json
{
  "id": "123",
  "shop_code": "MSK_001",
  "source": "storefront",
  "is_paid": true,
  "payment_type": "card",
  "updated_at": "2024-01-15T10:31:00Z"
}
```

**order.cancelled**
```json
{
  "id": "123",
  "shop_code": "MSK_001",
  "source": "storefront",
  "status": "cancelled",
  "updated_at": "2024-01-15T12:00:00Z"
}
```

## 5. События (Incoming)

### 5.1 Shop Events

**shop.created / shop.updated**
- Создать/обновить магазин
- Обновить расписание работы

**shop.deleted**
- Soft delete магазина

### 5.2 Category Events

**category.created / category.updated**
- Найти магазин по `shop_code`
- Создать/обновить категорию
- Связать с родительской категорией

**category.deleted**
- Soft delete категории

### 5.3 Product Events

**product.created / product.updated**
- Найти магазин и категорию
- Маппинг цен (инверсия)
- Извлечь КБЖУ из attributes
- Создать/обновить товар

**product.deleted**
- Soft delete товара

**product.stock.changed**
- Обновить quantity товара

### 5.4 Customer Events (от CRM)

**customer.created / customer.updated**
- Найти пользователя по phone или external_id
- Обновить данные профиля
- Сохранить external_id

### 5.5 Order Events (от CRM)

**order.status.changed**
- Найти заказ по external_id
- Маппинг статуса
- Обновить tracking_number

## 6. Реализация

### 6.1 Конфигурация

```php
// config/queue.php
'connections' => [
    'rabbitmq' => [
        'driver' => 'rabbitmq',
        'host' => env('RABBITMQ_HOST', 'localhost'),
        'port' => env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', 'guest'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
        'exchange' => env('RABBITMQ_EXCHANGE', 'default'),
        'exchange_type' => 'topic',
    ],
],
```

```
# .env
RABBITMQ_HOST=localhost
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/
```

### 6.2 Message Publisher

```php
class RabbitMQPublisher
{
    public function publish(string $exchange, string $routingKey, array $data): void
    {
        $message = [
            'data' => $data,
            'metadata' => [
                'source' => 'storefront',
                'timestamp' => now()->toIso8601String(),
                'correlation_id' => Str::uuid()->toString(),
            ],
        ];

        $this->connection->exchange($exchange)
            ->publish(
                message: json_encode($message),
                routingKey: $routingKey,
                options: [
                    'content_type' => 'application/json',
                    'delivery_mode' => 2, // Persistent
                ],
            );
    }
}
```

### 6.3 Event Jobs

```php
// app/Jobs/SendCustomerCreatedEvent.php
class SendCustomerCreatedEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public User $user
    ) {}

    public function handle(RabbitMQPublisher $publisher): void
    {
        $publisher->publish(
            exchange: 'customer',
            routingKey: 'customer.created',
            data: CustomerEventData::fromUser($this->user)->toArray(),
        );
    }
}
```

### 6.4 Event Consumers

```php
// app/Console/Commands/ConsumeRabbitMQ.php
class ConsumeRabbitMQ extends Command
{
    protected $signature = 'rabbitmq:consume {queue}';

    public function handle(): void
    {
        $queue = $this->argument('queue');
        $handler = $this->getHandler($queue);

        $this->connection->queue($queue)->consume(function ($message) use ($handler) {
            try {
                $data = json_decode($message->body, true);
                $handler->handle($data);
                $message->ack();
            } catch (Exception $e) {
                Log::error('RabbitMQ consumer error', [
                    'queue' => $queue,
                    'error' => $e->getMessage(),
                    'message' => $message->body,
                ]);
                $message->nack(requeue: false);
                // Отправить в DLQ
            }
        });
    }

    private function getHandler(string $queue): MessageHandler
    {
        return match ($queue) {
            'storefront.shop' => app(ShopEventHandler::class),
            'storefront.category' => app(CategoryEventHandler::class),
            'storefront.product' => app(ProductEventHandler::class),
            'storefront.customer' => app(CustomerEventHandler::class),
            'storefront.order' => app(OrderEventHandler::class),
        };
    }
}
```

### 6.5 Event Handlers

```php
// app/Services/RabbitMQ/Handlers/ProductEventHandler.php
class ProductEventHandler implements MessageHandler
{
    public function handle(array $message): void
    {
        $event = $message['metadata']['routing_key'] ?? '';
        $data = $message['data'];

        match ($event) {
            'product.created', 'product.updated' => $this->upsertProduct($data),
            'product.deleted' => $this->deleteProduct($data),
            'product.stock.changed' => $this->updateStock($data),
            default => Log::warning("Unknown product event: {$event}"),
        };
    }

    private function upsertProduct(array $data): void
    {
        $store = Store::where('code', $data['shop_code'])->first();
        if (!$store) {
            Log::warning("Store not found: {$data['shop_code']}");
            return;
        }

        $product = Product::updateOrCreate(
            [
                'store_id' => $store->id,
                'external_id' => $data['id'],
                'source' => $data['source'],
            ],
            $this->mapProductData($data, $store),
        );

        $this->syncImages($product, $data);
        $this->syncAttributes($product, $data);
    }
}
```

### 6.6 Supervisor для consumers

```ini
# /etc/supervisor/conf.d/storefront-rabbitmq.conf
[program:storefront-rabbitmq-shop]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/storefront/artisan rabbitmq:consume storefront.shop
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/storefront/rabbitmq-shop.log

[program:storefront-rabbitmq-product]
...

[program:storefront-rabbitmq-order]
...
```

## 7. Обработка ошибок

### 7.1 Dead Letter Queue (DLQ)

```php
// При создании очереди
$channel->queue_declare(
    queue: 'storefront.product',
    arguments: [
        'x-dead-letter-exchange' => 'dlx',
        'x-dead-letter-routing-key' => 'storefront.product.dlq',
    ],
);
```

### 7.2 Retry Logic

```php
public function handle(array $message): void
{
    $retries = $message['metadata']['retries'] ?? 0;
    $maxRetries = 3;

    try {
        $this->process($message);
    } catch (RetryableException $e) {
        if ($retries < $maxRetries) {
            $message['metadata']['retries'] = $retries + 1;
            $this->republish($message, delay: 2 ** $retries * 1000);
        } else {
            $this->sendToDLQ($message, $e);
        }
    } catch (Exception $e) {
        $this->sendToDLQ($message, $e);
    }
}
```

### 7.3 Идемпотентность

```php
// Проверка дубликатов
$correlationId = $message['metadata']['correlation_id'];

if (ProcessedMessage::where('correlation_id', $correlationId)->exists()) {
    Log::info("Duplicate message skipped: {$correlationId}");
    return;
}

// Обработка
$this->process($message);

// Сохранение
ProcessedMessage::create([
    'correlation_id' => $correlationId,
    'processed_at' => now(),
]);
```

## 8. Ожидаемый результат

### Код
- [ ] Config: `config/rabbitmq.php`
- [ ] `RabbitMQPublisher` — публикация сообщений
- [ ] `ConsumeRabbitMQ` — Artisan command
- [ ] Event Data classes: `CustomerEventData`, `OrderEventData`, etc.
- [ ] Jobs: `SendCustomerCreatedEvent`, `SendOrderCreatedEvent`, etc.
- [ ] Handlers: `ShopEventHandler`, `ProductEventHandler`, etc.
- [ ] Миграция `create_processed_messages_table` (для идемпотентности)

### Тесты
- [ ] `RabbitMQPublisherTest::testPublish`
- [ ] `ShopEventHandlerTest::testCreatesStore`
- [ ] `ProductEventHandlerTest::testCreatesProduct`
- [ ] `ProductEventHandlerTest::testPriceMapping`
- [ ] `OrderEventHandlerTest::testStatusChanged`
- [ ] `IdempotencyTest::testDuplicateMessageSkipped`

### Commands
```php
// routes/console.php
Artisan::command('rabbitmq:setup', function () {
    // Создание exchanges и queues
});

Artisan::command('rabbitmq:consume {queue}', function ($queue) {
    // Потребление сообщений
});
```

## 9. Мониторинг

### Метрики для отслеживания
- Количество сообщений в очереди
- Время обработки сообщения
- Количество ошибок / DLQ
- Lag между публикацией и обработкой

### Алерты
- Очередь > 1000 сообщений
- DLQ > 10 сообщений
- Consumer не активен > 5 минут
