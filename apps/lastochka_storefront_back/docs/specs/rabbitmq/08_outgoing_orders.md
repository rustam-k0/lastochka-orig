# RabbitMQ: Исходящие — Заказы (orders)

**Приоритет:** Критический
**Зависимости:** `01_setup.md`, `07_outgoing_customers.md`

---

## 1. Цель

Реализовать публикацию событий заказов через `CustomEventPublisher` (не через Observer, т.к. нужен полный payload со snapshot'ами).

---

## 2. События

| Routing Key | Когда публикуется |
|-------------|-------------------|
| `order.created` | Заказ создан (после успешной транзакции) |
| `order.paid` | Заказ оплачен |
| `order.cancelled` | Заказ отменён |

---

## 3. Исходящие payload'ы

### 3.1 order.created

```json
{
  "event_id": "uuid",
  "event_type": "created",
  "event_timestamp": "2024-01-15T10:30:00Z",
  "event_source": "storefront",
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
      "product_id": "ext_123",
      "product_source": "bagisto",
      "product_name": "Молоко 3.2%",
      "unit_price": 89.00,
      "unit_price_effective": 76.00,
      "line_subtotal": 178.00,
      "line_discount_total": 26.00,
      "line_total": 152.00,
      "quantity": 2
    }
  ],
  "status": "created",
  "items_subtotal": 500.00,
  "discount_total": 50.00,
  "items_total_after_discount": 450.00,
  "shipping_total": 200.00,
  "grand_total": 650.00,
  "is_paid": false,
  "payment_type": "card",
  "slot_start_date": "2024-01-15T10:00:00Z",
  "slot_end_date": "2024-01-15T12:00:00Z",
  "shipping_method": "courier",
  "address": {
    "address": "Москва, ул. Ленина, д. 10",
    "entrance": "2",
    "intercom": "42",
    "floor": "5",
    "flat": "42",
    "latitude": 55.7558,
    "longitude": 37.6173
  },
  "promocode": {
    "code": "SALE10",
    "discount_percent": 10
  },
  "created_at": "2024-01-15T10:30:00Z"
}
```

### 3.2 order.paid

```json
{
  "event_id": "uuid",
  "event_type": "paid",
  "event_timestamp": "2024-01-15T10:31:00Z",
  "event_source": "storefront",
  "id": "123",
  "shop_code": "MSK_001",
  "source": "storefront",
  "is_paid": true,
  "payment_type": "card",
  "updated_at": "2024-01-15T10:31:00Z"
}
```

### 3.3 order.cancelled

```json
{
  "event_id": "uuid",
  "event_type": "cancelled",
  "event_timestamp": "2024-01-15T12:00:00Z",
  "event_source": "storefront",
  "id": "123",
  "shop_code": "MSK_001",
  "source": "storefront",
  "status": "cancelled",
  "updated_at": "2024-01-15T12:00:00Z"
}
```

---

## 4. Реализация

### 4.1 Конфигурация entity

```php
'orders' => [
    'enabled' => false, // НЕ через Observer — только CustomEventPublisher
    'model' => \App\Models\Order::class,
    'transformer' => \App\RabbitMQ\Transformers\OrderEventTransformer::class,
    'routing_key_prefix' => 'order',
    'exchange' => 'default',
    // Для входящих событий от CRM
    'routing_key_patterns' => [
        'order.status.changed',
    ],
],
```

### 4.2 Transformer

```php
<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\Enums\OrderStatus;
use App\Models\Order;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class OrderEventTransformer extends BaseEventTransformer
{
    /**
     * Преобразовать заказ в событие order.created.
     */
    public function toEvent(Model $order, ?Model $shop = null, ?string $eventId = null): array
    {
        $order->load(['user', 'store', 'items', 'address', 'promocode']);

        return [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => $order->updated_at->toIso8601String(),
            'event_source' => config('rabbitmq-events.source'),
            'id' => (string) $order->id,
            'shop_code' => $order->store->code,
            'source' => config('rabbitmq-events.source'),
            'client_id' => (string) $order->user_id,
            'client_source' => config('rabbitmq-events.source'),
            'client_info' => $this->buildClientInfo($order),
            'items' => $this->buildItems($order),
            'status' => $order->status->value,
            'items_subtotal' => (float) $order->items_subtotal,
            'discount_total' => (float) $order->discount_total,
            'items_total_after_discount' => (float) $order->items_total_after_discount,
            'shipping_total' => (float) $order->shipping_total,
            'grand_total' => (float) $order->grand_total,
            'is_paid' => $order->is_paid,
            'payment_type' => $order->payment_type?->value,
            'slot_start_date' => $order->slot_start?->toIso8601String(),
            'slot_end_date' => $order->slot_end?->toIso8601String(),
            'shipping_method' => $order->shipping_method?->value,
            'address' => $this->buildAddress($order),
            'promocode' => $this->buildPromocode($order),
            'created_at' => $order->created_at->toIso8601String(),
        ];
    }

    /**
     * Кастомный payload для события order.paid.
     */
    public function toCustomEvent(
        Model $order,
        string $eventType,
        array $customPayload,
        ?string $eventId = null
    ): ?array {
        if ($eventType === 'paid') {
            return [
                'is_paid' => true,
                'payment_type' => $order->payment_type?->value,
                'updated_at' => $order->updated_at->toIso8601String(),
            ];
        }

        if ($eventType === 'cancelled') {
            return [
                'status' => 'cancelled',
                'updated_at' => $order->updated_at->toIso8601String(),
            ];
        }

        return null;
    }

    /**
     * Обработка входящего события order.status.changed от CRM.
     */
    public function fromEvent(array $data): array
    {
        return [
            'status' => $this->mapStatus($data['status']),
            'tracking_number' => $data['tracking_number'] ?? null,
        ];
    }

    public function validate(array $data): bool
    {
        return isset($data['event_id'], $data['id'], $data['source']);
    }

    /**
     * Custom event handler: order.status.changed
     */
    public function processStatusChangedEvent(Model $order, array $eventData): void
    {
        $newStatus = $this->mapStatus($eventData['status']);

        $order->update([
            'status' => $newStatus,
            'tracking_number' => $eventData['tracking_number'] ?? $order->tracking_number,
        ]);

        // TODO: Триггер push-уведомления
    }

    /**
     * Маппинг статусов из CRM.
     */
    private function mapStatus(string $crmStatus): OrderStatus
    {
        return match ($crmStatus) {
            'created' => OrderStatus::Pending,
            'assembling' => OrderStatus::Assembling,
            'assembled' => OrderStatus::Assembled,
            'in_delivery' => OrderStatus::InDelivery,
            'delivered' => OrderStatus::Delivered,
            'cancelled', 'returned' => OrderStatus::Cancelled,
            default => OrderStatus::Pending,
        };
    }

    private function buildClientInfo(Order $order): array
    {
        return [
            'name' => $order->user?->first_name,
            'surname' => $order->user?->last_name,
            'phone' => $order->user?->phone,
            'email' => $order->user?->email,
        ];
    }

    private function buildItems(Order $order): array
    {
        return $order->items->map(function ($item) {
            // Получить external_id товара из metadata
            $productMeta = EventSyncMetadata::where('entity_type', 'products')
                ->where('entity_id', $item->product_id)
                ->first();

            return [
                'id' => (string) $item->id,
                'source' => config('rabbitmq-events.source'),
                'product_id' => $productMeta?->external_id ?? (string) $item->product_id,
                'product_source' => $productMeta?->source ?? config('rabbitmq-events.source'),
                'product_name' => $item->product_title,
                'unit_price' => (float) $item->unit_price,
                'unit_price_effective' => (float) $item->unit_price_effective,
                'line_subtotal' => (float) $item->line_subtotal,
                'line_discount_total' => (float) $item->line_discount_total,
                'line_total' => (float) $item->line_total,
                'quantity' => $item->quantity,
            ];
        })->toArray();
    }

    private function buildAddress(Order $order): ?array
    {
        $address = $order->address;

        if (!$address) {
            return null;
        }

        return [
            'address' => $address->address,
            'entrance' => $address->entrance,
            'intercom' => $address->intercom,
            'floor' => $address->floor,
            'flat' => $address->flat,
            'latitude' => $address->latitude,
            'longitude' => $address->longitude,
        ];
    }

    private function buildPromocode(Order $order): ?array
    {
        $promocode = $order->promocode;

        if (!$promocode) {
            return null;
        }

        return [
            'code' => $promocode->code,
            'discount_percent' => $promocode->value,
        ];
    }
}
```

### 4.3 Интеграция с CreateOrderAction

```php
<?php

namespace App\Actions\Order;

use App\Models\Order;
use Comrades\RabbitMQEvents\Services\CustomEventPublisher;
use Illuminate\Support\Facades\DB;

class CreateOrderAction
{
    public function execute(CreateOrderData $data): Order
    {
        return DB::transaction(function () use ($data) {
            // 1. Создать заказ
            $order = Order::create([...]);

            // 2. Создать items
            foreach ($data->items as $item) {
                $order->items()->create([...]);
            }

            // 3. Создать address snapshot
            $order->address()->create([...]);

            // 4. Создать promocode snapshot (если есть)
            if ($data->promocode) {
                $order->promocode()->create([...]);
            }

            // 5. Зарезервировать товары
            $this->reserveProducts($order);

            // 6. Опубликовать событие order.created
            CustomEventPublisher::dispatch(
                entityType: 'orders',
                model: $order,
                eventType: 'created',
                customPayload: [], // Полный payload из toEvent()
                shopCode: $order->store->code
            );

            return $order;
        });
    }
}
```

### 4.4 Интеграция с PaymentService

```php
public function markOrderAsPaid(Order $order): void
{
    $order->update(['is_paid' => true]);

    CustomEventPublisher::dispatch(
        entityType: 'orders',
        model: $order,
        eventType: 'paid',
        customPayload: [],
        shopCode: $order->store->code
    );
}
```

### 4.5 Интеграция с CancelOrderAction

```php
public function execute(Order $order): void
{
    $order->update(['status' => OrderStatus::Cancelled]);

    // Вернуть резерв товаров
    $this->releaseProducts($order);

    CustomEventPublisher::dispatch(
        entityType: 'orders',
        model: $order,
        eventType: 'cancelled',
        customPayload: [],
        shopCode: $order->store->code
    );
}
```

### 4.6 Миграция

```php
// add_external_id_to_orders_table
Schema::table('orders', function (Blueprint $table) {
    $table->string('external_id')->nullable()->after('id');
    $table->string('external_source')->nullable()->after('external_id');
    $table->string('tracking_number')->nullable();
    $table->index(['external_id', 'external_source']);
});
```

---

## 5. Тесты

```php
public function test_order_created_event_published(): void
{
    $store = Store::factory()->create(['code' => 'MSK_001']);
    $user = User::factory()->create();
    $product = Product::factory()->for($store)->create();

    // Создаём заказ через action
    $order = app(CreateOrderAction::class)->execute(new CreateOrderData(
        userId: $user->id,
        storeId: $store->id,
        items: [
            new OrderItemData(
                productId: $product->id,
                quantity: 2,
            ),
        ],
        // ...
    ));

    // Проверяем outbox
    $this->assertDatabaseHas('event_outbox', [
        'entity_type' => 'orders',
        'entity_id' => $order->id,
        'event_type' => 'created',
    ]);

    $outbox = \Comrades\RabbitMQEvents\Models\EventOutbox::where('entity_id', $order->id)->first();
    $payload = json_decode($outbox->payload, true);

    $this->assertEquals('MSK_001', $payload['shop_code']);
    $this->assertEquals('created', $payload['status']);
    $this->assertCount(1, $payload['items']);
}

public function test_order_paid_event_published(): void
{
    $order = Order::factory()->create(['is_paid' => false]);

    app(PaymentService::class)->markOrderAsPaid($order);

    $this->assertDatabaseHas('event_outbox', [
        'entity_type' => 'orders',
        'entity_id' => $order->id,
        'event_type' => 'paid',
    ]);
}

public function test_order_status_changed_from_crm(): void
{
    $store = Store::factory()->create(['code' => 'MSK_001']);
    $order = Order::factory()->for($store)->create(['status' => OrderStatus::Pending]);

    // Создаём metadata
    EventSyncMetadata::create([
        'entity_type' => 'orders',
        'entity_id' => $order->id,
        'external_id' => 'crm_order_123',
        'source' => 'retailcrm',
        'shop_code' => 'MSK_001',
        'last_event_id' => Str::uuid()->toString(),
        'last_event_timestamp' => now()->subMinute(),
    ]);

    $eventData = [
        'event_id' => Str::uuid()->toString(),
        'event_type' => 'status_changed',
        'event_timestamp' => now()->toIso8601String(),
        'id' => 'crm_order_123',
        'source' => 'retailcrm',
        'shop_code' => 'MSK_001',
        'status' => 'delivered',
        'tracking_number' => 'TRACK123',
    ];

    $consumer = new EventConsumer('orders', config('rabbitmq-events.entities.orders'));
    $consumer->processEvent($eventData);

    $order->refresh();
    $this->assertEquals(OrderStatus::Delivered, $order->status);
    $this->assertEquals('TRACK123', $order->tracking_number);
}
```

---

## 6. Ожидаемый результат

### Файлы
- [ ] `app/RabbitMQ/Transformers/OrderEventTransformer.php`
- [ ] Миграция `add_external_id_to_orders_table`
- [ ] Обновлён `CreateOrderAction`
- [ ] Обновлён `PaymentService`
- [ ] Обновлён `CancelOrderAction`

### Тесты
- [ ] `OrderPublisherTest::test_order_created_event_published`
- [ ] `OrderPublisherTest::test_order_paid_event_published`
- [ ] `OrderPublisherTest::test_order_cancelled_event_published`
- [ ] `OrderPublisherTest::test_order_status_changed_from_crm`
- [ ] `OrderPublisherTest::test_items_include_external_product_ids`

### Команды
```bash
# Запустить outbox publisher
php artisan rabbitmq:publish-outbox orders

# Consumer для входящих статусов от CRM
php artisan rabbitmq:consume orders
```

---

## 7. Критерии готовности

- [ ] `order.created` публикуется с полным payload
- [ ] `order.paid` публикуется при оплате
- [ ] `order.cancelled` публикуется при отмене
- [ ] `order.status.changed` от CRM обновляет статус
- [ ] Items содержат external_id товаров
- [ ] Тесты проходят
