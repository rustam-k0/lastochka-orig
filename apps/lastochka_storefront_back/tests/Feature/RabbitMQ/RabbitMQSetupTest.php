<?php

namespace Tests\Feature\RabbitMQ;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RabbitMQSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_config_exists(): void
    {
        $this->assertNotNull(config('rabbitmq-events'));
        $this->assertNotNull(config('rabbitmq-events.connection'));
        $this->assertNotNull(config('rabbitmq-events.exchanges'));
        $this->assertNotNull(config('rabbitmq-events.inbound_queues'));
        $this->assertNotNull(config('rabbitmq-events.outbox'));
        $this->assertNotNull(config('rabbitmq-events.consumer'));
    }

    public function test_config_has_correct_source(): void
    {
        $this->assertEquals('storefront', config('rabbitmq-events.source'));
    }

    public function test_config_has_required_connection_fields(): void
    {
        $connection = config('rabbitmq-events.connection');

        $this->assertArrayHasKey('host', $connection);
        $this->assertArrayHasKey('port', $connection);
        $this->assertArrayHasKey('user', $connection);
        $this->assertArrayHasKey('password', $connection);
        $this->assertArrayHasKey('vhost', $connection);
    }

    public function test_config_has_all_inbound_queues(): void
    {
        $queues = config('rabbitmq-events.inbound_queues');

        $expectedQueues = [
            'shops',
            'categories',
            'products',
            'stories',
            'merchant_sales',
            'merchant_product_groups',
            'merchant_banners',
            'merchant_promocodes',
            'store_reviews',
            'product_reviews',
            'orders',
        ];

        foreach ($expectedQueues as $queue) {
            $this->assertArrayHasKey($queue, $queues, "Missing queue: {$queue}");
        }
    }

    public function test_migrations_applied(): void
    {
        $this->assertTrue(
            Schema::hasTable('event_sync_metadata'),
            'event_sync_metadata table should exist'
        );
        $this->assertTrue(
            Schema::hasTable('event_outbox'),
            'event_outbox table should exist'
        );
        $this->assertTrue(
            Schema::hasTable('event_pending_references'),
            'event_pending_references table should exist'
        );
    }

    public function test_event_sync_metadata_has_required_columns(): void
    {
        $columns = Schema::getColumnListing('event_sync_metadata');

        $requiredColumns = [
            'id',
            'entity_type',
            'entity_id',
            'external_id',
            'source',
            'shop_code',
            'last_event_id',
            'last_event_timestamp',
        ];

        foreach ($requiredColumns as $column) {
            $this->assertContains($column, $columns, "Missing column: {$column}");
        }
    }

    public function test_event_outbox_has_required_columns(): void
    {
        $columns = Schema::getColumnListing('event_outbox');

        $requiredColumns = [
            'id',
            'entity_type',
            'entity_id',
            'event_type',
            'routing_key',
            'payload',
            'published_at',
        ];

        foreach ($requiredColumns as $column) {
            $this->assertContains($column, $columns, "Missing column: {$column}");
        }
    }

    public function test_base_event_transformer_class_exists(): void
    {
        $this->assertTrue(
            class_exists(\App\RabbitMQ\Transformers\BaseEventTransformer::class),
            'BaseEventTransformer class should exist'
        );
    }

    public function test_base_event_transformer_implements_interface(): void
    {
        $reflection = new \ReflectionClass(\App\RabbitMQ\Transformers\BaseEventTransformer::class);

        $this->assertTrue(
            $reflection->implementsInterface(\Comrades\RabbitMQEvents\Contracts\EventTransformer::class),
            'BaseEventTransformer should implement EventTransformer interface'
        );
    }
}
