<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\RabbitMQ\Debug\RabbitDebugService;
use App\RabbitMQ\Debug\RabbitMessageLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RabbitDebugServiceTest extends TestCase
{
    use RefreshDatabase;

    private RabbitDebugService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new RabbitDebugService;

        config(['rabbit-debug.enabled' => true]);
        config(['rabbit-debug.async_logging' => false]);
    }

    public function test_log_inbound_creates_pending_log(): void
    {
        $logId = $this->service->logInbound(
            routingKey: 'product.created',
            payload: ['id' => 'ext_123', 'title' => 'Test Product'],
            queueName: 'storefront_products',
        );

        $this->assertNotNull($logId);

        $this->assertDatabaseHas('rabbit_message_log', [
            'id' => $logId,
            'direction' => 'inbound',
            'routing_key' => 'product.created',
            'queue_name' => 'storefront_products',
            'external_id' => 'ext_123',
            'status' => 'pending',
        ]);
    }

    public function test_log_outbound_creates_processed_log(): void
    {
        $logId = $this->service->logOutbound(
            routingKey: 'order.order_created',
            payload: ['id' => 'ord_456', 'total' => 1500],
            entityType: 'orders',
            entityId: 123,
        );

        $this->assertNotNull($logId);

        $this->assertDatabaseHas('rabbit_message_log', [
            'id' => $logId,
            'direction' => 'outbound',
            'routing_key' => 'order.order_created',
            'entity_type' => 'orders',
            'entity_id' => 123,
            'external_id' => 'ord_456',
            'status' => 'processed',
        ]);
    }

    public function test_mark_processed_updates_log(): void
    {
        $logId = $this->service->logInbound(
            routingKey: 'product.created',
            payload: ['id' => 'ext_789'],
        );

        $this->service->markProcessed(
            logId: $logId,
            entityType: 'products',
            entityId: 456,
            dbSnapshot: ['table' => 'products', 'id' => 456, 'data' => ['title' => 'Test']],
        );

        $log = RabbitMessageLog::find($logId);

        $this->assertEquals('processed', $log->status);
        $this->assertEquals('products', $log->entity_type);
        $this->assertEquals(456, $log->entity_id);
        $this->assertNotNull($log->db_snapshot);
        $this->assertEquals('products', $log->db_snapshot['table']);
    }

    public function test_mark_failed_updates_log_with_error(): void
    {
        $logId = $this->service->logInbound(
            routingKey: 'product.created',
            payload: ['id' => 'ext_fail'],
        );

        $exception = new \Exception('Test error message');

        $this->service->markFailed($exception, $logId);

        $log = RabbitMessageLog::find($logId);

        $this->assertEquals('failed', $log->status);
        $this->assertEquals('Test error message', $log->error_message);
        $this->assertNotNull($log->error_trace);
    }

    public function test_hidden_fields_are_sanitized(): void
    {
        $logId = $this->service->logInbound(
            routingKey: 'customer.created',
            payload: [
                'id' => 'cust_123',
                'name' => 'John Doe',
                'password' => 'secret123',
                'token' => 'abc123xyz',
                'nested' => [
                    'api_key' => 'key_123',
                    'data' => 'visible',
                ],
            ],
        );

        $log = RabbitMessageLog::find($logId);

        $this->assertEquals('John Doe', $log->payload['name']);
        $this->assertEquals('[HIDDEN]', $log->payload['password']);
        $this->assertEquals('[HIDDEN]', $log->payload['token']);
        $this->assertEquals('[HIDDEN]', $log->payload['nested']['api_key']);
        $this->assertEquals('visible', $log->payload['nested']['data']);
    }

    public function test_logging_disabled_returns_null(): void
    {
        config(['rabbit-debug.enabled' => false]);

        $logId = $this->service->logInbound(
            routingKey: 'product.created',
            payload: ['id' => 'ext_123'],
        );

        $this->assertNull($logId);
        $this->assertDatabaseMissing('rabbit_message_log', [
            'routing_key' => 'product.created',
        ]);
    }

    public function test_create_db_snapshot_from_model(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'title' => 'Test Product',
            'price' => 100,
        ]);

        $snapshot = $this->service->createDbSnapshot($product);

        $this->assertEquals('products', $snapshot['table']);
        $this->assertEquals($product->id, $snapshot['id']);
        $this->assertArrayHasKey('data', $snapshot);
        $this->assertEquals('Test Product', $snapshot['data']['title']);
    }

    public function test_processing_time_is_calculated(): void
    {
        $logId = $this->service->logInbound(
            routingKey: 'product.created',
            payload: ['id' => 'ext_time'],
        );

        usleep(50000); // 50ms

        $this->service->markProcessed($logId);

        $log = RabbitMessageLog::find($logId);

        $this->assertNotNull($log->processing_time_ms);
        $this->assertGreaterThan(40, $log->processing_time_ms);
    }

    public function test_current_log_id_is_tracked(): void
    {
        $logId = $this->service->logInbound(
            routingKey: 'product.created',
            payload: ['id' => 'ext_track'],
        );

        $this->assertEquals($logId, $this->service->getCurrentLogId());

        $this->service->markProcessed();

        $this->assertNull($this->service->getCurrentLogId());
    }
}
