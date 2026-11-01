<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\RabbitMQ\Debug\RabbitMessageLog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RabbitDebugControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rabbit-debug.enabled' => true]);
    }

    public function test_index_returns_paginated_messages(): void
    {
        RabbitMessageLog::factory()->count(5)->create();

        $response = $this->getJson('/api/debug/rabbit/messages');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'direction',
                        'routing_key',
                        'entity_type',
                        'entity_id',
                        'external_id',
                        'status',
                        'processing_time_ms',
                        'created_at',
                    ],
                ],
                'meta' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                ],
            ])
            ->assertJsonPath('meta.total', 5);
    }

    public function test_index_filters_by_direction(): void
    {
        RabbitMessageLog::factory()->inbound()->count(3)->create();
        RabbitMessageLog::factory()->outbound()->count(2)->create();

        $response = $this->getJson('/api/debug/rabbit/messages?direction=inbound');

        $response->assertOk()
            ->assertJsonPath('meta.total', 3);
    }

    public function test_index_filters_by_status(): void
    {
        RabbitMessageLog::factory()->processed()->count(4)->create();
        RabbitMessageLog::factory()->failed()->count(1)->create();

        $response = $this->getJson('/api/debug/rabbit/messages?status=failed');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_index_filters_by_routing_key_wildcard(): void
    {
        RabbitMessageLog::factory()->create(['routing_key' => 'product.created']);
        RabbitMessageLog::factory()->create(['routing_key' => 'product.updated']);
        RabbitMessageLog::factory()->create(['routing_key' => 'order.created']);

        $response = $this->getJson('/api/debug/rabbit/messages?routing_key=product.*');

        $response->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_index_filters_by_entity_type(): void
    {
        RabbitMessageLog::factory()->create(['entity_type' => 'products']);
        RabbitMessageLog::factory()->create(['entity_type' => 'products']);
        RabbitMessageLog::factory()->create(['entity_type' => 'orders']);

        $response = $this->getJson('/api/debug/rabbit/messages?entity_type=products');

        $response->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_index_filters_by_date_range(): void
    {
        RabbitMessageLog::factory()->create(['created_at' => Carbon::now()->subDays(3)]);
        RabbitMessageLog::factory()->create(['created_at' => Carbon::now()->subDay()]);
        RabbitMessageLog::factory()->create(['created_at' => Carbon::now()]);

        $from = Carbon::now()->subDays(2)->toDateTimeString();

        $response = $this->getJson("/api/debug/rabbit/messages?date_from={$from}");

        $response->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_show_returns_message_details(): void
    {
        $log = RabbitMessageLog::factory()->create([
            'direction' => 'inbound',
            'routing_key' => 'product.created',
            'queue_name' => 'storefront_products',
            'payload' => ['id' => 'ext_123', 'title' => 'Test'],
            'entity_type' => 'products',
            'entity_id' => 456,
            'status' => 'processed',
            'db_snapshot' => ['table' => 'products', 'id' => 456],
            'processing_time_ms' => 32,
        ]);

        $response = $this->getJson("/api/debug/rabbit/messages/{$log->id}");

        $response->assertOk()
            ->assertJsonPath('id', $log->id)
            ->assertJsonPath('direction', 'inbound')
            ->assertJsonPath('routing_key', 'product.created')
            ->assertJsonPath('queue_name', 'storefront_products')
            ->assertJsonPath('payload.id', 'ext_123')
            ->assertJsonPath('entity_type', 'products')
            ->assertJsonPath('entity_id', 456)
            ->assertJsonPath('status', 'processed')
            ->assertJsonPath('db_snapshot.table', 'products')
            ->assertJsonPath('processing_time_ms', 32);
    }

    public function test_show_returns_404_for_nonexistent(): void
    {
        $response = $this->getJson('/api/debug/rabbit/messages/99999');

        $response->assertNotFound();
    }

    public function test_stats_returns_24h_statistics(): void
    {
        // Old messages (should not be counted)
        RabbitMessageLog::factory()->inbound()->processed()->create([
            'created_at' => Carbon::now()->subDays(2),
        ]);

        // Recent messages
        RabbitMessageLog::factory()->inbound()->processed()->count(3)->create([
            'processing_time_ms' => 30,
        ]);
        RabbitMessageLog::factory()->inbound()->failed()->create();
        RabbitMessageLog::factory()->outbound()->processed()->count(2)->create();

        $response = $this->getJson('/api/debug/rabbit/stats');

        $response->assertOk()
            ->assertJsonPath('period', '24h')
            ->assertJsonPath('inbound.total', 4)
            ->assertJsonPath('inbound.processed', 3)
            ->assertJsonPath('inbound.failed', 1)
            ->assertJsonPath('outbound.total', 2)
            ->assertJsonPath('outbound.processed', 2)
            ->assertJsonPath('outbound.failed', 0)
            ->assertJsonStructure([
                'by_routing_key',
                'avg_processing_time_ms',
            ]);
    }

    public function test_destroy_deletes_old_logs(): void
    {
        RabbitMessageLog::factory()->create(['created_at' => Carbon::now()->subDays(10)]);
        RabbitMessageLog::factory()->create(['created_at' => Carbon::now()->subDays(10)]);
        RabbitMessageLog::factory()->create(['created_at' => Carbon::now()]);

        $response = $this->deleteJson('/api/debug/rabbit/messages?days=7');

        $response->assertOk()
            ->assertJsonPath('deleted', 2);

        $this->assertDatabaseCount('rabbit_message_log', 1);
    }

    public function test_routing_keys_returns_unique_keys(): void
    {
        RabbitMessageLog::factory()->create(['routing_key' => 'product.created']);
        RabbitMessageLog::factory()->create(['routing_key' => 'product.created']);
        RabbitMessageLog::factory()->create(['routing_key' => 'order.created']);

        $response = $this->getJson('/api/debug/rabbit/routing-keys');

        $response->assertOk()
            ->assertJsonStructure(['data'])
            ->assertJsonCount(2, 'data');
    }
}
