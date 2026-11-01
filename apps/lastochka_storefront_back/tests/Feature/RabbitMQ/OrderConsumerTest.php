<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPromocode;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OrderConsumerTest extends TestCase
{
    use RefreshDatabase;

    public function test_after_update_syncs_items_quantity_and_price(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();
        $product = Product::factory()->for($store)->create();

        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Pending,
            'subtotal' => 1250,
            'discount' => 0,
            'delivery_cost' => 200,
            'total' => 1450,
            'items_count' => 1,
        ]);

        $item = OrderItem::factory()->for($order)->for($product)->create([
            'title' => 'Яблоко красное, кг',
            'price' => 125,
            'quantity' => 10,
            'total' => 1250,
        ]);

        $transformer = new OrderEventTransformer;

        // Имитируем входящее событие order.updated от delivery_back
        // Сборщик изменил количество с 10 на 8
        $eventData = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'delivery_order_uuid',
            'source' => 'delivery',
            'shop_code' => 'MSK_001',
            'status' => 'assembled',
            'grand_total' => 1200.0,
            'items' => [
                [
                    'id' => (string) $item->id,
                    'source' => config('rabbitmq-events.source'), // storefront — наш source
                    'product_id' => (string) $product->id,
                    'product_source' => config('rabbitmq-events.source'),
                    'product_name' => 'Яблоко красное, кг',
                    'quantity' => 8,
                    'unit_price' => 125.0,
                    'unit_price_effective' => 125.0,
                    'line_subtotal' => 1000.0,
                    'line_total' => 1000.0,
                ],
            ],
        ];

        $transformer->afterUpdate($order, $eventData);

        // Проверяем обновление позиции заказа
        $item->refresh();
        $this->assertEquals(8, $item->quantity);
        $this->assertEquals(125, $item->price);
        $this->assertEquals(1000, $item->total);

        // Проверяем пересчёт итогов заказа
        $order->refresh();
        $this->assertEquals(1000, $order->subtotal);
        $this->assertEquals(1200, $order->total); // 1000 - 0 + 200
        $this->assertEquals(1, $order->items_count);
    }

    public function test_after_update_handles_multiple_items(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();
        $product1 = Product::factory()->for($store)->create();
        $product2 = Product::factory()->for($store)->create();

        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Pending,
            'subtotal' => 500,
            'discount' => 0,
            'delivery_cost' => 100,
            'total' => 600,
            'items_count' => 2,
        ]);

        $item1 = OrderItem::factory()->for($order)->for($product1)->create([
            'title' => 'Молоко',
            'price' => 100,
            'quantity' => 3,
            'total' => 300,
        ]);

        $item2 = OrderItem::factory()->for($order)->for($product2)->create([
            'title' => 'Хлеб',
            'price' => 50,
            'quantity' => 4,
            'total' => 200,
        ]);

        $transformer = new OrderEventTransformer;
        $currentSource = config('rabbitmq-events.source');

        $eventData = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'delivery_order_uuid',
            'source' => 'delivery',
            'shop_code' => 'MSK_001',
            'status' => 'assembled',
            'items' => [
                [
                    'id' => (string) $item1->id,
                    'source' => $currentSource,
                    'product_name' => 'Молоко',
                    'quantity' => 2,
                    'unit_price' => 100.0,
                    'line_total' => 200.0,
                ],
                [
                    'id' => (string) $item2->id,
                    'source' => $currentSource,
                    'product_name' => 'Хлеб',
                    'quantity' => 3,
                    'unit_price' => 50.0,
                    'line_total' => 150.0,
                ],
            ],
        ];

        $transformer->afterUpdate($order, $eventData);

        $item1->refresh();
        $this->assertEquals(2, $item1->quantity);
        $this->assertEquals(200, $item1->total);

        $item2->refresh();
        $this->assertEquals(3, $item2->quantity);
        $this->assertEquals(150, $item2->total);

        $order->refresh();
        $this->assertEquals(350, $order->subtotal);
        $this->assertEquals(450, $order->total); // 350 + 100 delivery
        $this->assertEquals(2, $order->items_count);
    }

    public function test_after_update_skips_when_no_items_in_event(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();

        $order = Order::factory()->for($user)->for($store)->create([
            'subtotal' => 500,
            'total' => 700,
        ]);

        $transformer = new OrderEventTransformer;

        $eventData = [
            'event_id' => Str::uuid()->toString(),
            'id' => 'delivery_order_uuid',
            'source' => 'delivery',
            'status' => 'assembled',
        ];

        $transformer->afterUpdate($order, $eventData);

        $order->refresh();
        $this->assertEquals(500, $order->subtotal);
        $this->assertEquals(700, $order->total);
    }

    public function test_after_update_matches_items_by_product_name_fallback(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();
        $product = Product::factory()->for($store)->create();

        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Pending,
            'subtotal' => 576,
            'discount' => 0,
            'delivery_cost' => 100,
            'total' => 676,
            'items_count' => 1,
        ]);

        $item = OrderItem::factory()->for($order)->for($product)->create([
            'title' => 'Крупа Кукурузная',
            'price' => 72,
            'quantity' => 8,
            'total' => 576,
        ]);

        $transformer = new OrderEventTransformer;

        // Событие от delivery_back: source="app", id — это delivery_back'овский ID (не наш)
        $eventData = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'delivery_order_uuid',
            'source' => 'app',
            'shop_code' => 'MSK_001',
            'status' => 'assembled',
            'items' => [
                [
                    'id' => '999',        // delivery_back's local ID
                    'source' => 'app',    // delivery_back's source
                    'product_name' => 'Крупа Кукурузная',
                    'quantity' => 5,
                    'unit_price' => 72.0,
                    'unit_price_effective' => 72.0,
                    'line_total' => 360.0,
                ],
            ],
        ];

        $transformer->afterUpdate($order, $eventData);

        $item->refresh();
        $this->assertEquals(5, $item->quantity);
        $this->assertEquals(72, $item->price);
        $this->assertEquals(360, $item->total);

        $order->refresh();
        $this->assertEquals(360, $order->subtotal);
        $this->assertEquals(460, $order->total); // 360 + 100 delivery
    }

    public function test_after_update_uses_unit_price_effective_over_unit_price(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();
        $product = Product::factory()->for($store)->create();

        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Pending,
            'subtotal' => 1200,
            'discount' => 0,
            'delivery_cost' => 100,
            'total' => 1300,
            'items_count' => 1,
        ]);

        $item = OrderItem::factory()->for($order)->for($product)->create([
            'title' => 'Бананы кг',
            'price' => 120,
            'quantity' => 10,
            'total' => 1200,
        ]);

        $transformer = new OrderEventTransformer;

        // delivery_back после сборки отправляет unit_price = line total (баг),
        // но unit_price_effective = реальная цена за единицу
        $eventData = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'delivery_order_uuid',
            'source' => 'app',
            'shop_code' => 'MSK_001',
            'status' => 'assembled',
            'items' => [
                [
                    'id' => '999',
                    'source' => 'app',
                    'product_name' => 'Бананы кг',
                    'quantity' => 7,
                    'unit_price' => 840.0,              // line total (неверно)
                    'unit_price_effective' => 120.0,     // реальная цена за штуку
                    'line_total' => 5880.0,              // тоже неверно
                ],
            ],
        ];

        $transformer->afterUpdate($order, $eventData);

        $item->refresh();
        $this->assertEquals(7, $item->quantity);
        $this->assertEquals(120, $item->price);       // unit_price_effective, не unit_price
        $this->assertEquals(840, $item->total);        // 120 * 7

        $order->refresh();
        $this->assertEquals(840, $order->subtotal);
        $this->assertEquals(940, $order->total);       // 840 + 100 delivery
    }

    public function test_after_update_with_discount_recalculates_total_correctly(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();
        $product = Product::factory()->for($store)->create();

        $order = Order::factory()->for($user)->for($store)->create([
            'subtotal' => 1000,
            'discount' => 100,
            'delivery_cost' => 150,
            'total' => 1050,
            'items_count' => 1,
        ]);

        $item = OrderItem::factory()->for($order)->for($product)->create([
            'title' => 'Товар',
            'price' => 200,
            'quantity' => 5,
            'total' => 1000,
        ]);

        $transformer = new OrderEventTransformer;

        // Сборщик уменьшил количество до 3
        $eventData = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'delivery_order_uuid',
            'source' => 'delivery',
            'shop_code' => 'MSK_001',
            'status' => 'assembled',
            'items' => [
                [
                    'id' => (string) $item->id,
                    'source' => config('rabbitmq-events.source'),
                    'product_name' => 'Товар',
                    'quantity' => 3,
                    'unit_price' => 200.0,
                    'line_total' => 600.0,
                ],
            ],
        ];

        $transformer->afterUpdate($order, $eventData);

        $order->refresh();
        $this->assertEquals(600, $order->subtotal);
        // total = subtotal - discount + delivery_cost = 600 - 100 + 150 = 650
        $this->assertEquals(650, $order->total);
    }

    public function test_after_update_sets_confirmed_status_on_assembled_item(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();
        $product = Product::factory()->for($store)->create();

        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Pending,
            'subtotal' => 500,
            'discount' => 0,
            'delivery_cost' => 100,
            'total' => 600,
            'items_count' => 1,
        ]);

        $item = OrderItem::factory()->for($order)->for($product)->create([
            'title' => 'Молоко',
            'price' => 100,
            'quantity' => 5,
            'total' => 500,
        ]);

        $transformer = new OrderEventTransformer;

        $eventData = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'id' => 'delivery_order_uuid',
            'source' => 'delivery',
            'shop_code' => 'MSK_001',
            'status' => 'assembled',
            'items' => [
                [
                    'id' => (string) $item->id,
                    'source' => config('rabbitmq-events.source'),
                    'product_name' => 'Молоко',
                    'quantity' => 5,
                    'unit_price' => 100.0,
                    'unit_price_effective' => 100.0,
                    'item_status' => 'confirmed',
                ],
            ],
        ];

        $transformer->afterUpdate($order, $eventData);

        $item->refresh();
        $this->assertEquals('confirmed', $item->status);
        $this->assertNull($item->origin_product_id);
    }

    public function test_after_update_sets_cancelled_status_on_cancelled_item(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();
        $product = Product::factory()->for($store)->create();

        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Pending,
            'subtotal' => 300,
            'discount' => 0,
            'delivery_cost' => 100,
            'total' => 400,
            'items_count' => 1,
        ]);

        $item = OrderItem::factory()->for($order)->for($product)->create([
            'title' => 'Кефир',
            'price' => 150,
            'quantity' => 2,
            'total' => 300,
        ]);

        $transformer = new OrderEventTransformer;

        $eventData = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'id' => 'delivery_order_uuid',
            'source' => 'delivery',
            'shop_code' => 'MSK_001',
            'status' => 'assembled',
            'items' => [
                [
                    'id' => (string) $item->id,
                    'source' => config('rabbitmq-events.source'),
                    'product_name' => 'Кефир',
                    'quantity' => 2,
                    'unit_price' => 150.0,
                    'unit_price_effective' => 150.0,
                    'item_status' => 'cancelled',
                ],
            ],
        ];

        $transformer->afterUpdate($order, $eventData);

        $item->refresh();
        $this->assertEquals('cancelled', $item->status);
    }

    public function test_after_update_sets_replaced_status_with_origin_data(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();
        $product = Product::factory()->for($store)->create();

        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Pending,
            'subtotal' => 400,
            'discount' => 0,
            'delivery_cost' => 100,
            'total' => 500,
            'items_count' => 1,
        ]);

        $item = OrderItem::factory()->for($order)->for($product)->create([
            'title' => 'Яблоки Гала',
            'price' => 200,
            'quantity' => 2,
            'total' => 400,
        ]);

        // origin_product_id из события — ВНЕШНИЙ id товара курьерки; в
        // order_items кладётся уже локальный id, резолв идёт через metadata.
        $originProduct = Product::factory()->for($store)->create(['title' => 'Яблоки Фуджи']);
        EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $originProduct->id,
            'external_id' => '999',
            'source' => config('rabbitmq-events.source'),
            'shop_code' => 'MSK_001',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $transformer = new OrderEventTransformer;

        $eventData = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'id' => 'delivery_order_uuid',
            'source' => 'delivery',
            'shop_code' => 'MSK_001',
            'status' => 'assembled',
            'items' => [
                [
                    'id' => (string) $item->id,
                    'source' => config('rabbitmq-events.source'),
                    'product_name' => 'Яблоки Гала',
                    'quantity' => 2,
                    'unit_price' => 180.0,
                    'unit_price_effective' => 180.0,
                    'item_status' => 'replaced',
                    'origin_product_id' => '999',
                    'origin_product_name' => 'Яблоки Фуджи',
                    'origin_quantity' => 2,
                    'origin_price' => 200.0,
                ],
            ],
        ];

        $transformer->afterUpdate($order, $eventData);

        $item->refresh();
        $this->assertEquals('replaced', $item->status);
        $this->assertEquals($originProduct->id, $item->origin_product_id);
        $this->assertEquals('Яблоки Фуджи', $item->origin_title);
        $this->assertEquals(200, $item->origin_price);
        $this->assertEquals(2, $item->origin_quantity);
    }

    public function test_after_update_creates_new_item_when_added(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();
        $product = Product::factory()->for($store)->create();

        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Pending,
            'subtotal' => 200,
            'discount' => 0,
            'delivery_cost' => 100,
            'total' => 300,
            'items_count' => 1,
        ]);

        $existingItem = OrderItem::factory()->for($order)->for($product)->create([
            'title' => 'Хлеб',
            'price' => 50,
            'quantity' => 4,
            'total' => 200,
        ]);

        $transformer = new OrderEventTransformer;

        $eventData = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'id' => 'delivery_order_uuid',
            'source' => 'delivery',
            'shop_code' => 'MSK_001',
            'status' => 'assembled',
            'items' => [
                [
                    'id' => (string) $existingItem->id,
                    'source' => config('rabbitmq-events.source'),
                    'product_name' => 'Хлеб',
                    'quantity' => 4,
                    'unit_price' => 50.0,
                    'unit_price_effective' => 50.0,
                    'item_status' => 'confirmed',
                ],
                [
                    'id' => '9999',
                    'source' => 'delivery',
                    'product_name' => 'Батон нарезной',
                    'quantity' => 1,
                    'unit_price' => 45.0,
                    'unit_price_effective' => 45.0,
                    'item_status' => 'added',
                ],
            ],
        ];

        $transformer->afterUpdate($order, $eventData);

        // Проверяем что новый item создан
        $this->assertEquals(2, $order->items()->count());

        $newItem = $order->items()->where('title', 'Батон нарезной')->first();
        $this->assertNotNull($newItem);
        $this->assertEquals('added', $newItem->status);
        $this->assertEquals(45, $newItem->price);
        $this->assertEquals(1, $newItem->quantity);
        $this->assertEquals(45, $newItem->total);

        // Проверяем пересчёт итогов
        $order->refresh();
        $this->assertEquals(245, $order->subtotal); // 200 + 45
        $this->assertEquals(345, $order->total);     // 245 + 100 delivery
        $this->assertEquals(2, $order->items_count);
    }

    /**
     * Тест замены товара сборщиком: admin_back отправляет _origin (replaced) + added для одного item.
     * Оригинальный товар должен получить status=replaced, товар-замена — создаться как новый с status=added.
     */
    public function test_after_update_replacement_creates_two_items_origin_and_added(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();
        $originalProduct = Product::factory()->for($store)->create(['title' => 'Мясо говядины б/к кт']);
        $replacementProduct = Product::factory()->for($store)->create(['title' => 'Масло сливочное']);
        $normalProduct = Product::factory()->for($store)->create(['title' => 'Зефир']);

        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Pending,
            'subtotal' => 748,
            'discount' => 0,
            'delivery_cost' => 0,
            'total' => 748,
            'items_count' => 2,
        ]);

        $originalItem = OrderItem::factory()->for($order)->for($originalProduct)->create([
            'title' => 'Мясо говядины б/к кт',
            'price' => 302,
            'quantity' => 1,
            'total' => 302,
        ]);

        $normalItem = OrderItem::factory()->for($order)->for($normalProduct)->create([
            'title' => 'Зефир',
            'price' => 300,
            'quantity' => 1,
            'total' => 300,
        ]);

        $transformer = new OrderEventTransformer;
        $currentSource = config('rabbitmq-events.source');

        // Событие от admin_back: сборщик заменил "Мясо говядины" на "Масло сливочное"
        // admin_back отправляет ДВА элемента для замены:
        // 1. {id}_origin с item_status=replaced — оригинальный товар
        // 2. {id} с item_status=added — товар-замена
        $eventData = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'id' => 'delivery_order_uuid',
            'source' => 'delivery',
            'shop_code' => 'MSK_001',
            'status' => 'assembled',
            'items' => [
                // Обычный товар — собран
                [
                    'id' => (string) $normalItem->id,
                    'source' => $currentSource,
                    'product_id' => (string) $normalProduct->id,
                    'product_source' => $currentSource,
                    'product_name' => 'Зефир',
                    'quantity' => 1,
                    'unit_price' => 300.0,
                    'unit_price_effective' => 300.0,
                    'line_subtotal' => 300.0,
                    'line_total' => 300.0,
                    'item_status' => 'confirmed',
                ],
                // Оригинальный товар — заменён (суффикс _origin)
                [
                    'id' => $originalItem->id . '_origin',
                    'source' => $currentSource,
                    'product_id' => (string) $originalProduct->id,
                    'product_source' => $currentSource,
                    'product_name' => 'Мясо говядины б/к кт',
                    'quantity' => 1,
                    'unit_price' => 302.0,
                    'unit_price_effective' => 302.0,
                    'line_subtotal' => 0,
                    'line_total' => 0,
                    'item_status' => 'replaced',
                ],
                // Товар-замена — добавлен (тот же базовый ID, без суффикса)
                [
                    'id' => (string) $originalItem->id,
                    'source' => $currentSource,
                    'product_id' => (string) $replacementProduct->id,
                    'product_source' => $currentSource,
                    'product_name' => 'Масло сливочное',
                    'quantity' => 1,
                    'unit_price' => 250.0,
                    'unit_price_effective' => 250.0,
                    'line_subtotal' => 250.0,
                    'line_total' => 250.0,
                    'item_status' => 'added',
                ],
            ],
        ];

        $transformer->afterUpdate($order, $eventData);

        // 1. Оригинальный товар должен получить status=replaced
        $originalItem->refresh();
        $this->assertEquals('replaced', $originalItem->status, 'Оригинальный товар должен иметь статус replaced');
        $this->assertEquals('Мясо говядины б/к кт', $originalItem->title);

        // 2. Должен быть создан новый item для товара-замены
        $this->assertEquals(3, $order->items()->count(), 'Должно быть 3 позиции: обычная, заменённая, замена');

        $addedItem = $order->items()->where('title', 'Масло сливочное')->first();
        $this->assertNotNull($addedItem, 'Товар-замена должен быть создан как новая позиция');
        $this->assertEquals('added', $addedItem->status);
        $this->assertEquals(250, $addedItem->price);
        $this->assertEquals(1, $addedItem->quantity);
        $this->assertEquals(250, $addedItem->total);

        // 3. Origin-данные на товаре-замене (прокинуты из _origin записи)
        $this->assertEquals('Мясо говядины б/к кт', $addedItem->origin_title);
        $this->assertEquals(302, $addedItem->origin_price);
        $this->assertEquals(1, $addedItem->origin_quantity);

        // 4. Обычный товар не затронут
        $normalItem->refresh();
        $this->assertEquals('confirmed', $normalItem->status);

        // 5. Пересчёт итогов: только активные позиции (confirmed + added, без replaced)
        $order->refresh();
        $this->assertEquals(550, $order->subtotal); // 300 (зефир) + 250 (масло)
        $this->assertEquals(550, $order->total);     // 550 - 0 discount + 0 delivery
        $this->assertEquals(2, $order->items_count); // только активные
    }

    /**
     * Тест: замена + отмена в одном заказе.
     */
    public function test_after_update_replacement_and_cancellation_together(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();
        $product1 = Product::factory()->for($store)->create(['title' => 'Мясо говядины']);
        $product2 = Product::factory()->for($store)->create(['title' => 'Куриные ножки']);
        $product3 = Product::factory()->for($store)->create(['title' => 'Зефир']);
        $replacementProduct = Product::factory()->for($store)->create(['title' => 'Масло сливочное']);

        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Pending,
            'subtotal' => 748,
            'discount' => 0,
            'delivery_cost' => 0,
            'total' => 748,
            'items_count' => 3,
        ]);

        $item1 = OrderItem::factory()->for($order)->for($product1)->create([
            'title' => 'Мясо говядины',
            'price' => 302,
            'quantity' => 1,
            'total' => 302,
        ]);

        $item2 = OrderItem::factory()->for($order)->for($product2)->create([
            'title' => 'Куриные ножки',
            'price' => 146,
            'quantity' => 1,
            'total' => 146,
        ]);

        $item3 = OrderItem::factory()->for($order)->for($product3)->create([
            'title' => 'Зефир',
            'price' => 300,
            'quantity' => 1,
            'total' => 300,
        ]);

        $transformer = new OrderEventTransformer;
        $currentSource = config('rabbitmq-events.source');

        $eventData = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'id' => 'delivery_order_uuid',
            'source' => 'delivery',
            'shop_code' => 'MSK_001',
            'status' => 'assembled',
            'items' => [
                // Зефир — собран
                [
                    'id' => (string) $item3->id,
                    'source' => $currentSource,
                    'product_name' => 'Зефир',
                    'quantity' => 1,
                    'unit_price' => 300.0,
                    'unit_price_effective' => 300.0,
                    'item_status' => 'confirmed',
                ],
                // Мясо говядины — заменено (origin)
                [
                    'id' => $item1->id . '_origin',
                    'source' => $currentSource,
                    'product_name' => 'Мясо говядины',
                    'quantity' => 1,
                    'unit_price' => 302.0,
                    'unit_price_effective' => 302.0,
                    'line_subtotal' => 0,
                    'line_total' => 0,
                    'item_status' => 'replaced',
                ],
                // Масло — замена за мясо
                [
                    'id' => (string) $item1->id,
                    'source' => $currentSource,
                    'product_name' => 'Масло сливочное',
                    'quantity' => 1,
                    'unit_price' => 250.0,
                    'unit_price_effective' => 250.0,
                    'item_status' => 'added',
                ],
                // Куриные ножки — отменены
                [
                    'id' => (string) $item2->id,
                    'source' => $currentSource,
                    'product_name' => 'Куриные ножки',
                    'quantity' => 1,
                    'unit_price' => 146.0,
                    'unit_price_effective' => 146.0,
                    'item_status' => 'cancelled',
                ],
            ],
        ];

        $transformer->afterUpdate($order, $eventData);

        $item1->refresh();
        $this->assertEquals('replaced', $item1->status, 'Мясо говядины — replaced');

        $item2->refresh();
        $this->assertEquals('cancelled', $item2->status, 'Куриные ножки — cancelled');

        $item3->refresh();
        $this->assertEquals('confirmed', $item3->status, 'Зефир — confirmed');

        $addedItem = $order->items()->where('title', 'Масло сливочное')->first();
        $this->assertNotNull($addedItem, 'Масло должно быть создано как новая позиция');
        $this->assertEquals('added', $addedItem->status);

        // Итого: 4 позиции, из них активных 2 (зефир 300 + масло 250)
        $this->assertEquals(4, $order->items()->count());

        $order->refresh();
        $this->assertEquals(550, $order->subtotal); // 300 + 250
        $this->assertEquals(550, $order->total);
        $this->assertEquals(2, $order->items_count);
    }

    public function test_after_update_recalculates_percent_discount_on_subtotal_change(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();
        $product = Product::factory()->for($store)->create(['price_old' => null]);

        // Заказ на 1752р с 10% промокодом, скидка 175
        $order = Order::factory()->for($user)->for($store)->create([
            'subtotal' => 1752,
            'discount' => 175,
            'delivery_cost' => 0,
            'total' => 1577,
            'items_count' => 1,
        ]);

        OrderPromocode::factory()->percent(10)->for($order)->create([
            'discount_amount' => 175,
        ]);

        $item = OrderItem::factory()->for($order)->for($product)->create([
            'title' => 'Ягода протёртая',
            'price' => 146,
            'quantity' => 12,
            'total' => 1752,
        ]);

        $transformer = new OrderEventTransformer;

        // Сборщик увеличил количество до 20
        $eventData = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'id' => 'delivery_order_uuid',
            'source' => 'delivery',
            'shop_code' => 'MSK_001',
            'status' => 'assembled',
            'items' => [
                [
                    'id' => (string) $item->id,
                    'source' => config('rabbitmq-events.source'),
                    'product_name' => 'Ягода протёртая',
                    'quantity' => 20,
                    'unit_price' => 146.0,
                    'unit_price_effective' => 146.0,
                    'item_status' => 'confirmed',
                ],
            ],
        ];

        $transformer->afterUpdate($order, $eventData);

        $order->refresh();
        $this->assertEquals(2920, $order->subtotal);   // 146 * 20
        $this->assertEquals(292, $order->discount);     // 10% от 2920
        $this->assertEquals(2628, $order->total);       // 2920 - 292

        // Проверяем что discount_amount в order_promocodes тоже обновлён
        $order->load('promocode');
        $this->assertEquals(292, $order->promocode->discount_amount);
    }

    public function test_after_update_recalculates_delivery_cost_when_free_threshold_reached(): void
    {
        $store = Store::factory()->create([
            'code' => 'MSK_001',
            'delivery_price' => 200,
            'free_delivery_from' => 750,
        ]);
        $user = User::factory()->create();
        $product = Product::factory()->for($store)->create();

        // Заказ на 600р с платной доставкой 200р
        $order = Order::factory()->for($user)->for($store)->create([
            'subtotal' => 600,
            'discount' => 0,
            'delivery_cost' => 200,
            'total' => 800,
            'items_count' => 1,
        ]);

        $item = OrderItem::factory()->for($order)->for($product)->create([
            'title' => 'Товар',
            'price' => 200,
            'quantity' => 3,
            'total' => 600,
        ]);

        $transformer = new OrderEventTransformer;

        // Сборщик добавил товары, subtotal стал > 750
        $eventData = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'id' => 'delivery_order_uuid',
            'source' => 'delivery',
            'shop_code' => 'MSK_001',
            'status' => 'assembled',
            'items' => [
                [
                    'id' => (string) $item->id,
                    'source' => config('rabbitmq-events.source'),
                    'product_name' => 'Товар',
                    'quantity' => 5,
                    'unit_price' => 200.0,
                    'unit_price_effective' => 200.0,
                    'item_status' => 'confirmed',
                ],
            ],
        ];

        $transformer->afterUpdate($order, $eventData);

        $order->refresh();
        $this->assertEquals(1000, $order->subtotal);
        $this->assertEquals(0, $order->delivery_cost);  // бесплатная доставка (1000 >= 750)
        $this->assertEquals(1000, $order->total);        // 1000 - 0 + 0
    }
}
