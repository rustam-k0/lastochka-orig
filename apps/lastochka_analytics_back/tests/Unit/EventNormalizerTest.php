<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\EventNormalizer;
use PHPUnit\Framework\TestCase;

final class EventNormalizerTest extends TestCase
{
    private EventNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new EventNormalizer;
    }

    private function envelope(array $overrides = []): array
    {
        return array_merge([
            'event_id' => '11111111-1111-1111-1111-111111111111',
            'event_type' => 'updated',
            'event_timestamp' => '2026-07-10T12:00:00+03:00',
            'id' => 'order-uuid-1',
            'source' => 'delivery',
            'event_source' => 'delivery',
            'shop_code' => 'lastochka',
            'metadata' => ['routing_key' => 'order.updated'],
        ], $overrides);
    }

    public function test_normalizes_basic_envelope_fields(): void
    {
        $row = $this->normalizer->normalize($this->envelope());

        $this->assertSame('11111111-1111-1111-1111-111111111111', $row['event_id']);
        $this->assertSame('order.updated', $row['event_type']);
        $this->assertSame('delivery', $row['source']);
        $this->assertSame('lastochka', $row['store_id']);
        $this->assertSame('2026-07-10T12:00:00+03:00', $row['occurred_at']->toIso8601String());
        $this->assertIsArray($row['payload']);
    }

    public function test_order_events_take_order_id_from_envelope_id(): void
    {
        $row = $this->normalizer->normalize($this->envelope());

        $this->assertSame('order-uuid-1', $row['order_id']);
        $this->assertSame('order-uuid-1', $row['correlation_id']);
    }

    public function test_payload_order_id_and_client_and_session_win(): void
    {
        $row = $this->normalizer->normalize($this->envelope([
            'order_id' => 'order-uuid-2',
            'client_id' => '42',
            'session_id' => 'sess-1',
        ]));

        $this->assertSame('order-uuid-2', $row['order_id']);
        $this->assertSame('42', $row['client_id']);
        $this->assertSame('sess-1', $row['session_id']);
    }

    public function test_order_stage_routing_key_maps_to_spec_name(): void
    {
        $row = $this->normalizer->normalize($this->envelope([
            'metadata' => ['routing_key' => 'order_stage.changed'],
            'actor_type' => 'staff',
            'actor_id' => 7,
        ]));

        $this->assertSame('order.stage_changed', $row['event_type']);
        $this->assertSame('7', $row['employee_id']);
        $this->assertSame('staff', $row['role']);
    }

    public function test_order_stage_from_system_actor_has_no_employee(): void
    {
        $row = $this->normalizer->normalize($this->envelope([
            'metadata' => ['routing_key' => 'order_stage.changed'],
            'actor_type' => 'system',
            'actor_id' => null,
        ]));

        $this->assertNull($row['employee_id']);
        $this->assertNull($row['role']);
    }

    public function test_picking_events_extract_picker(): void
    {
        $row = $this->normalizer->normalize($this->envelope([
            'metadata' => ['routing_key' => 'picking.item_assembled'],
            'picker_id' => 3,
        ]));

        $this->assertSame('picking.item_assembled', $row['event_type']);
        $this->assertSame('3', $row['employee_id']);
        $this->assertSame('picker', $row['role']);
    }

    public function test_delivery_events_extract_courier(): void
    {
        $row = $this->normalizer->normalize($this->envelope([
            'metadata' => ['routing_key' => 'delivery.started'],
            'courier_id' => 9,
        ]));

        $this->assertSame('9', $row['employee_id']);
        $this->assertSame('courier', $row['role']);
    }

    public function test_shift_events_extract_employee_and_role_from_payload(): void
    {
        $row = $this->normalizer->normalize($this->envelope([
            'metadata' => ['routing_key' => 'shift.started'],
            'employee_id' => 3,
            'role' => 'picker',
            'session_id' => 'shift-15',
        ]));

        $this->assertSame('shift.started', $row['event_type']);
        $this->assertSame('3', $row['employee_id']);
        $this->assertSame('picker', $row['role']);
        $this->assertSame('shift-15', $row['session_id']);
        $this->assertNull($row['order_id']);
    }

    public function test_b2c_events_strip_prefix_and_use_client_occurred_at(): void
    {
        $row = $this->normalizer->normalize($this->envelope([
            'metadata' => ['routing_key' => 'b2c.cart.item_added'],
            'event_source' => 'storefront',
            'client_id' => 'anon-uuid',
            'session_id' => 'sess-uuid',
            'occurred_at' => '2026-07-10T11:55:00+03:00',
        ]));

        $this->assertSame('cart.item_added', $row['event_type']);
        $this->assertSame('b2c_app', $row['source']);
        $this->assertSame('client', $row['role']);
        $this->assertSame('2026-07-10T11:55:00+03:00', $row['occurred_at']->toIso8601String());
    }

    public function test_order_review_maps_to_rating_received(): void
    {
        // #11601: оценка заказа клиентом = rating.received эпика B2E
        $row = $this->normalizer->normalize($this->envelope([
            'metadata' => ['routing_key' => 'order_review.created'],
            'event_source' => 'storefront',
            'id' => '17',
            'order_id' => 'order-uuid-9',
            'user_id' => '42',
            'rating' => 5,
        ]));

        $this->assertSame('rating.received', $row['event_type']);
        $this->assertSame('client', $row['role']);
        $this->assertSame('42', $row['client_id']);
        $this->assertSame('order-uuid-9', $row['order_id']);
        $this->assertNull($row['employee_id']);
    }

    public function test_order_review_update_maps_to_rating_updated(): void
    {
        $row = $this->normalizer->normalize($this->envelope([
            'metadata' => ['routing_key' => 'order_review.updated'],
            'event_source' => 'storefront',
            'order_id' => 'order-uuid-9',
            'user_id' => '42',
            'rating' => 3,
        ]));

        $this->assertSame('rating.updated', $row['event_type']);
        $this->assertSame('42', $row['client_id']);
    }

    public function test_far_future_occurred_at_is_clamped_to_now(): void
    {
        $row = $this->normalizer->normalize($this->envelope([
            'metadata' => ['routing_key' => 'b2c.app.opened'],
            'occurred_at' => now()->addYears(3)->toIso8601String(),
        ]));

        $this->assertTrue($row['occurred_at']->lessThanOrEqualTo(now()->addMinutes(1)));
    }

    public function test_missing_event_id_gets_generated(): void
    {
        $envelope = $this->envelope();
        unset($envelope['event_id']);

        $row = $this->normalizer->normalize($envelope);

        $this->assertNotEmpty($row['event_id']);
    }
}
