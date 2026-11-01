<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\RabbitMQ\Handlers\AnalyticsEventHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AnalyticsEventHandlerTest extends TestCase
{
    use RefreshDatabase;

    private AnalyticsEventHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('analytics:ensure-partitions');
        $this->handler = app(AnalyticsEventHandler::class);
    }

    private function envelope(array $overrides = []): array
    {
        return array_merge([
            'event_id' => '22222222-2222-2222-2222-222222222222',
            'event_type' => 'item_assembled',
            'event_timestamp' => now()->toIso8601String(),
            'id' => '15',
            'source' => 'delivery',
            'event_source' => 'delivery',
            'shop_code' => 'lastochka',
            'order_id' => 'order-uuid-1',
            'picker_id' => 3,
            'client_id' => '42',
            'metadata' => ['routing_key' => 'picking.item_assembled'],
        ], $overrides);
    }

    public function test_valid_event_is_stored_normalized(): void
    {
        $result = $this->handler->handle($this->envelope());

        $this->assertSame('ack', $result);

        $row = DB::table('analytics_events')->first();
        $this->assertSame('picking.item_assembled', $row->event_type);
        $this->assertSame('order-uuid-1', $row->order_id);
        $this->assertSame('3', $row->employee_id);
        $this->assertSame('picker', $row->role);
        $this->assertSame('42', $row->client_id);
        $this->assertSame('lastochka', $row->store_id);

        $payload = json_decode($row->payload, true);
        $this->assertSame('picking.item_assembled', $payload['metadata']['routing_key']);
    }

    public function test_duplicate_event_id_is_ignored(): void
    {
        $this->handler->handle($this->envelope());
        $result = $this->handler->handle($this->envelope());

        $this->assertSame('ack', $result);
        $this->assertSame(1, (int) DB::table('analytics_events')->count());
    }

    public function test_invalid_event_id_gets_regenerated_not_rejected(): void
    {
        $result = $this->handler->handle($this->envelope([
            'event_id' => 'not-a-uuid',
        ]));

        $this->assertSame('ack', $result);

        $row = DB::table('analytics_events')->first();
        $this->assertSame('picking.item_assembled', $row->event_type);
        // Сырой event_id сохранён в payload
        $this->assertSame('not-a-uuid', json_decode($row->payload, true)['event_id']);
    }

    public function test_broken_event_is_stored_as_unparsed_and_acked(): void
    {
        $this->mock(\App\Services\EventNormalizer::class, function ($mock) {
            $mock->shouldReceive('normalize')->andThrow(new \RuntimeException('boom'));
        });

        $handler = app(AnalyticsEventHandler::class);
        $result = $handler->handle($this->envelope());

        $this->assertSame('ack', $result);

        $row = DB::table('analytics_events')->first();
        $this->assertSame('_unparsed', $row->event_type);

        $payload = json_decode($row->payload, true);
        $this->assertSame('22222222-2222-2222-2222-222222222222', $payload['raw']['event_id']);
        $this->assertSame('boom', $payload['error']);
    }

    public function test_cyrillic_and_invalid_utf8_do_not_break_insert(): void
    {
        $result = $this->handler->handle($this->envelope([
            'client_comment' => "Позвонить за 10 минут \xB1\x31",
        ]));

        $this->assertSame('ack', $result);
        $this->assertSame(1, (int) DB::table('analytics_events')->where('event_type', 'picking.item_assembled')->count());
    }

    public function test_identity_linked_creates_link_and_event_row(): void
    {
        $result = $this->handler->handle([
            'event_id' => '33333333-3333-3333-3333-333333333333',
            'event_type' => 'identity.linked',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'anon-uuid-1',
            'source' => 'storefront',
            'event_source' => 'storefront',
            'shop_code' => 'lastochka',
            'anon_client_id' => 'anon-uuid-1',
            'user_id' => '42',
            'client_id' => '42',
            'metadata' => ['routing_key' => 'b2c.identity.linked'],
        ]);

        $this->assertSame('ack', $result);

        $link = DB::table('client_identity_links')->first();
        $this->assertSame('anon-uuid-1', $link->anon_client_id);
        $this->assertSame('42', $link->user_id);

        $this->assertSame(1, (int) DB::table('analytics_events')->where('event_type', 'identity.linked')->count());
    }

    public function test_identity_linked_is_idempotent(): void
    {
        $event = [
            'event_id' => '44444444-4444-4444-4444-444444444444',
            'event_type' => 'identity.linked',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'anon-uuid-1',
            'source' => 'storefront',
            'event_source' => 'storefront',
            'shop_code' => 'lastochka',
            'anon_client_id' => 'anon-uuid-1',
            'user_id' => '42',
            'metadata' => ['routing_key' => 'b2c.identity.linked'],
        ];

        $this->handler->handle($event);
        $event['event_id'] = '55555555-5555-5555-5555-555555555555';
        $this->handler->handle($event);

        $this->assertSame(1, (int) DB::table('client_identity_links')->count());
    }
}
