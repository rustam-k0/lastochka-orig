<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\RabbitMQ\Debug\LoggingEventConsumer;
use App\RabbitMQ\Debug\LoggingEventPublisher;
use App\RabbitMQ\Debug\RabbitMessageLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RabbitDebugIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rabbit-debug.enabled' => true]);
        config(['rabbit-debug.async_logging' => false]);
    }

    public function test_logging_event_publisher_logs_outbound_message(): void
    {
        $store = Store::factory()->create(['code' => 'TEST_STORE']);
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'store_id' => $store->id,
            'user_id' => $user->id,
        ]);

        LoggingEventPublisher::dispatch(
            entityType: 'orders',
            model: $order,
            eventType: 'order_created',
            payload: ['test' => true],
            shopCode: $store->code
        );

        $this->assertDatabaseHas('rabbit_message_log', [
            'direction' => 'outbound',
            'routing_key' => 'order.order_created',
            'entity_type' => 'orders',
            'entity_id' => $order->id,
            'status' => 'processed',
        ]);

        $log = RabbitMessageLog::where('entity_id', $order->id)->first();
        $this->assertArrayHasKey('event_type', $log->payload);
        $this->assertEquals('order_created', $log->payload['event_type']);
    }

    public function test_logging_event_consumer_logs_inbound_message_on_success(): void
    {
        $entityConfig = config('rabbitmq-events.entities.shops');
        $consumer = new LoggingEventConsumer('shops', $entityConfig, 'storefront_shops');

        $eventData = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'created',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'ext_shop_new_123',
            'source' => 'bagisto',
            'shop_code' => 'NEW_SHOP_CODE',
            'title' => 'New Test Store', // StoreEventTransformer uses 'title'
            'latitude' => 55.75,
            'longitude' => 37.62,
            'active' => true,
        ];

        $result = $consumer->processEvent($eventData, 'shop.created');

        $this->assertEquals('ack', $result);

        $this->assertDatabaseHas('rabbit_message_log', [
            'direction' => 'inbound',
            'routing_key' => 'shop.created',
            'queue_name' => 'storefront_shops',
            'external_id' => 'ext_shop_new_123',
            'status' => 'processed',
        ]);

        // Проверим что магазин создался
        $this->assertDatabaseHas('stores', [
            'code' => 'NEW_SHOP_CODE',
            'name' => 'New Test Store',
        ]);
    }

    public function test_logging_event_consumer_logs_failed_message(): void
    {
        $entityConfig = config('rabbitmq-events.entities.shops');
        $consumer = new LoggingEventConsumer('shops', $entityConfig, 'storefront_shops');

        // Событие без обязательного поля shop_code вызовет ошибку валидации
        $eventData = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'created',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'ext_invalid',
            'source' => 'bagisto',
            // Намеренно пропускаем shop_code (required)
        ];

        try {
            $consumer->processEvent($eventData, 'shop.created');
        } catch (\Exception $e) {
            // Ожидаем исключение InvalidEventException
        }

        $this->assertDatabaseHas('rabbit_message_log', [
            'direction' => 'inbound',
            'routing_key' => 'shop.created',
            'external_id' => 'ext_invalid',
            'status' => 'failed',
        ]);

        $log = RabbitMessageLog::where('external_id', 'ext_invalid')->first();
        $this->assertNotNull($log->error_message);
    }

    public function test_logging_disabled_does_not_create_logs(): void
    {
        config(['rabbit-debug.enabled' => false]);

        $store = Store::factory()->create(['code' => 'NO_LOG']);
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'store_id' => $store->id,
            'user_id' => $user->id,
        ]);

        LoggingEventPublisher::dispatch(
            entityType: 'orders',
            model: $order,
            eventType: 'order_test',
            payload: [],
            shopCode: $store->code
        );

        $this->assertDatabaseMissing('rabbit_message_log', [
            'routing_key' => 'order.order_test',
        ]);
    }

    public function test_shop_event_with_image_url_saves_to_image_path(): void
    {
        $entityConfig = config('rabbitmq-events.entities.shops');
        $consumer = new LoggingEventConsumer('shops', $entityConfig, 'storefront_shops');

        $imageUrl = 'https://domain.com/storage/shops/logo.jpg';

        // Витрина не хранит чужой URL: картинка скачивается и кладётся
        // в storage (BaseEventTransformer::downloadImage → 'stores/…'),
        // а в image_path попадает локальный путь.
        Storage::fake('public');
        Http::fake(['https://domain.com/*' => Http::response('fake-image', 200, ['Content-Type' => 'image/jpeg'])]);

        $eventData = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'created',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'ext_shop_with_image',
            'source' => 'aladdin_admin',
            'shop_code' => 'SHOP_WITH_IMAGE',
            'title' => 'Store With Image',
            'latitude' => 55.7558,
            'longitude' => 37.6173,
            'active' => true,
            'image_url' => $imageUrl,
        ];

        $result = $consumer->processEvent($eventData, 'shop.created');

        $this->assertEquals('ack', $result);

        $this->assertDatabaseHas('stores', [
            'code' => 'SHOP_WITH_IMAGE',
            'name' => 'Store With Image',
        ]);

        $imagePath = Store::where('code', 'SHOP_WITH_IMAGE')->value('image_path');
        $this->assertNotNull($imagePath);
        $this->assertNotSame($imageUrl, $imagePath);
        $this->assertStringStartsWith('stores/', $imagePath);
        Storage::disk('public')->assertExists($imagePath);
    }

    public function test_shop_event_without_image_url_sets_null_image_path(): void
    {
        $entityConfig = config('rabbitmq-events.entities.shops');
        $consumer = new LoggingEventConsumer('shops', $entityConfig, 'storefront_shops');

        $eventData = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'created',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'ext_shop_no_image',
            'source' => 'aladdin_admin',
            'shop_code' => 'SHOP_NO_IMAGE',
            'title' => 'Store Without Image',
            'latitude' => 55.7558,
            'longitude' => 37.6173,
            'active' => true,
            // image_url отсутствует — backward compatibility
        ];

        $result = $consumer->processEvent($eventData, 'shop.created');

        $this->assertEquals('ack', $result);

        $this->assertDatabaseHas('stores', [
            'code' => 'SHOP_NO_IMAGE',
            'name' => 'Store Without Image',
            'image_path' => null,
        ]);
    }

    public function test_shop_update_event_updates_image_path(): void
    {
        // Создаём магазин без картинки
        $store = Store::factory()->create([
            'code' => 'SHOP_UPDATE_IMAGE',
            'image_path' => null,
        ]);

        $entityConfig = config('rabbitmq-events.entities.shops');
        $consumer = new LoggingEventConsumer('shops', $entityConfig, 'storefront_shops');

        $newImageUrl = 'https://domain.com/storage/shops/new-logo.jpg';

        Storage::fake('public');
        Http::fake(['https://domain.com/*' => Http::response('fake-image', 200, ['Content-Type' => 'image/jpeg'])]);

        $eventData = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'ext_shop_update',
            'source' => 'aladdin_admin',
            'shop_code' => 'SHOP_UPDATE_IMAGE',
            'title' => 'Updated Store',
            'latitude' => 55.7558,
            'longitude' => 37.6173,
            'active' => true,
            'image_url' => $newImageUrl,
        ];

        $result = $consumer->processEvent($eventData, 'shop.updated');

        $this->assertEquals('ack', $result);

        $imagePath = Store::where('code', 'SHOP_UPDATE_IMAGE')->value('image_path');
        $this->assertNotNull($imagePath);
        $this->assertNotSame($newImageUrl, $imagePath);
        $this->assertStringStartsWith('stores/', $imagePath);
        Storage::disk('public')->assertExists($imagePath);
    }
}
