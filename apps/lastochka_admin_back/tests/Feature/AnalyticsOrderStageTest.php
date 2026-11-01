<?php

namespace Tests\Feature;

use App\Models\OrderHistory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * order_stage.changed (LASTOCHKI-JAMI-11659): каждая запись OrderHistory::log
 * отражается в event_outbox для lastochka_analytics_back.
 */
class AnalyticsOrderStageTest extends TestCase
{
    use DatabaseTransactions;

    public function test_order_history_log_publishes_order_stage_changed_to_outbox(): void
    {
        $clientId = \DB::table('clients')->insertGetId([
            'is_authorized' => 1,
            'newsletter_subscribed' => 0,
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $uuid = 'aost-' . uniqid();

        $orderId = \DB::table('orders')->insertGetId([
            'uuid' => $uuid,
            'discount' => 0,
            'payment_type_id' => 1,
            'paid' => 0,
            'type_id' => 2,
            'client_id' => $clientId,
            'status_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        OrderHistory::log(
            $orderId,
            OrderHistory::ACTION_STATUS_CHANGED,
            OrderHistory::ACTOR_SYSTEM,
            null,
            null,
            'Создан',
            'Отменён',
            'Отмена клиентом'
        );

        // Запись истории создана (существующее поведение не сломано)
        $this->assertDatabaseHas('order_history', [
            'order_id' => $orderId,
            'action' => 'status_changed',
        ]);

        $row = \DB::table('event_outbox')
            ->where('routing_key', 'order_stage.changed')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($row, 'Событие order_stage.changed не попало в outbox');

        $payload = json_decode($row->payload, true);

        $this->assertSame($uuid, $payload['order_id']);
        $this->assertSame($uuid, $payload['correlation_id']);
        $this->assertSame('status_changed', $payload['action']);
        $this->assertSame('system', $payload['actor_type']);
        $this->assertSame('Создан', $payload['old_value']);
        $this->assertSame('Отменён', $payload['new_value']);
        $this->assertSame('Отмена клиентом', $payload['comment']);
        $this->assertNotEmpty($payload['occurred_at']);
    }
}
