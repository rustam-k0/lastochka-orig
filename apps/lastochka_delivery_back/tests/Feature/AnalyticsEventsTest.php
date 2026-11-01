<?php

namespace Tests\Feature;

use App\Models\AssemblyItem;
use App\Models\OrderHistory;
use App\Models\Status;
use App\Models\Type;
use App\Services\AnalyticsEvents;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Публикация аналитических событий P0 (LASTOCHKI-JAMI-11659):
 * события уходят в event_outbox (transactional outbox) с routing-префиксами
 * picking.* / delivery.* / order_stage.* и контекстом заказа в payload.
 */
class AnalyticsEventsTest extends TestCase
{
    use DatabaseTransactions;

    private int $shopId;
    private int $clientId;
    private int $collectorId;
    private int $shiftId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientId = \DB::table('clients')->insertGetId([
            'is_authorized' => 1,
            'newsletter_subscribed' => 0,
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->shopId = \DB::table('shops')->insertGetId([
            'title' => 'Analytics events shop',
            'code' => 'analytics-test-shop',
            'id_1c' => random_int(900000, 999999),
            'default' => 0,
            'slot_max_orders' => 10,
            'slot_time' => 60,
            'is_popular' => 0,
            'is_pickup_enabled' => 0,
            'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 0,
            'is_bonus_enabled' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->collectorId = \DB::table('users')->insertGetId([
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Открытая смена сборщика: источник session_id рабочей сессии
        $this->shiftId = \DB::table('shifts')->insertGetId([
            'user_id' => $this->collectorId,
            'shop_id' => $this->shopId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertOrderWithAssemblyItem(): array
    {
        $productId = \DB::table('products')->insertGetId([
            'title' => 'Молоко 1л',
            'articul' => 'ANEV-' . uniqid(),
            'unit_type_id' => 1,
            'status_id' => 1,
            'fill_percent' => 0,
            'publish_after_unlock' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('prices')->insert([
            'product_id' => $productId,
            'type_id' => Type::PRICE_DEFAULT,
            'value' => 100,
            'step' => 1,
            'blocked' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderId = \DB::table('orders')->insertGetId([
            'shop_id' => $this->shopId,
            'uuid' => 'anev-' . uniqid(),
            'discount' => 0,
            'payment_type_id' => 1,
            'paid' => 0,
            'type_id' => 2,
            'client_id' => $this->clientId,
            'status_id' => Status::ORDER_PREPARING,
            'amount' => 1452.50,
            'slot_start_date' => '2026-07-15 18:00:00',
            'slot_end_date' => '2026-07-15 20:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderItemId = \DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'product_id' => $productId,
            'quantity' => 2,
            'price' => 200,
            'price_during_order' => 100,
            'status_id' => Status::ORDER_ITEM_WAITING,
            'is_supplemented' => 0,
            'is_eligible' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assemblyId = \DB::table('assemblies')->insertGetId([
            'order_id' => $orderId,
            'user_id' => $this->collectorId,
            'finished_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assemblyItemId = \DB::table('assembly_items')->insertGetId([
            'assembly_id' => $assemblyId,
            'order_item_id' => $orderItemId,
            'status_id' => Status::PRODUCT_ASSEMBLED,
            'type_id' => Type::PRODUCT_WITHOUT_PROBLEMS,
            'quantity' => 2,
            'price' => 200,
            'assembled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$orderId, $orderItemId, $assemblyId, $assemblyItemId];
    }

    private function latestOutbox(string $routingKey): ?object
    {
        return \DB::table('event_outbox')
            ->where('routing_key', $routingKey)
            ->orderByDesc('id')
            ->first();
    }

    public function test_item_assembled_publishes_picking_event_to_outbox(): void
    {
        [$orderId, , , $assemblyItemId] = $this->insertOrderWithAssemblyItem();

        $item = AssemblyItem::with(['orderItem.order', 'assembly'])->find($assemblyItemId);

        AnalyticsEvents::itemAssembled($item);

        $row = $this->latestOutbox('picking.item_assembled');
        $this->assertNotNull($row, 'Событие picking.item_assembled не попало в outbox');

        $payload = json_decode($row->payload, true);
        $orderUuid = \DB::table('orders')->where('id', $orderId)->value('uuid');

        $this->assertSame($orderUuid, $payload['order_id']);
        $this->assertSame($orderUuid, $payload['correlation_id']);
        $this->assertSame($this->collectorId, $payload['picker_id']);
        $this->assertSame(2, $payload['quantity']);
        $this->assertSame('Молоко 1л', $payload['product_title']);
        $this->assertSame('analytics-test-shop', $payload['shop_code']);
        $this->assertNotEmpty($payload['assembled_at']);
        $this->assertNotEmpty($payload['occurred_at']);
        // session_id рабочей сессии = открытая смена исполнителя
        $this->assertSame('shift-' . $this->shiftId, $payload['session_id']);
    }

    public function test_shift_started_and_ended_publish_session_events(): void
    {
        $roleId = \DB::table('roles')->where('name', 'collector')->value('id')
            ?? \DB::table('roles')->insertGetId(['name' => 'collector', 'guard_name' => 'api', 'created_at' => now(), 'updated_at' => now()]);
        \DB::table('model_has_roles')->insertOrIgnore([
            'role_id' => $roleId,
            'model_type' => \App\Models\User::class,
            'model_id' => $this->collectorId,
        ]);

        $shift = \App\Models\Shift::withoutGlobalScopes()->find($this->shiftId);

        AnalyticsEvents::shiftStarted($shift);

        $row = $this->latestOutbox('shift.started');
        $this->assertNotNull($row, 'Событие shift.started не попало в outbox');

        $payload = json_decode($row->payload, true);
        $this->assertSame('shift-' . $this->shiftId, $payload['session_id']);
        $this->assertSame($this->collectorId, $payload['employee_id']);
        $this->assertSame('picker', $payload['role']);

        $shift->update(['finished_at' => now()]);
        AnalyticsEvents::shiftEnded($shift->fresh());

        $row = $this->latestOutbox('shift.ended');
        $this->assertNotNull($row, 'Событие shift.ended не попало в outbox');

        $payload = json_decode($row->payload, true);
        $this->assertSame('shift-' . $this->shiftId, $payload['session_id']);
        $this->assertArrayHasKey('shift_seconds', $payload);
    }

    public function test_order_history_log_publishes_order_stage_changed(): void
    {
        [$orderId] = $this->insertOrderWithAssemblyItem();

        OrderHistory::log(
            $orderId,
            OrderHistory::ACTION_STATUS_CHANGED,
            OrderHistory::ACTOR_STAFF,
            $this->collectorId,
            'Сборщиков С.С.',
            'Создан',
            'Заказ на сборке'
        );

        // Сама запись истории создана
        $this->assertDatabaseHas('order_history', [
            'order_id' => $orderId,
            'action' => 'status_changed',
        ]);

        $row = $this->latestOutbox('order_stage.changed');
        $this->assertNotNull($row, 'Событие order_stage.changed не попало в outbox');

        $payload = json_decode($row->payload, true);

        $this->assertSame('status_changed', $payload['action']);
        $this->assertSame('staff', $payload['actor_type']);
        $this->assertSame($this->collectorId, $payload['actor_id']);
        $this->assertSame('Создан', $payload['old_value']);
        $this->assertSame('Заказ на сборке', $payload['new_value']);
        $this->assertNotEmpty($payload['order_id']);
    }

    public function test_picking_finished_publishes_fill_rate(): void
    {
        [$orderId, , $assemblyId] = $this->insertOrderWithAssemblyItem();

        $assembly = \App\Models\Assembly::with('order')->find($assemblyId);

        AnalyticsEvents::pickingFinished($assembly);

        $row = $this->latestOutbox('picking.finished');
        $this->assertNotNull($row, 'Событие picking.finished не попало в outbox');

        $payload = json_decode($row->payload, true);

        $this->assertSame(1, $payload['ordered_items']);
        $this->assertSame(1, $payload['assembled_items']);
        $this->assertSame(0, $payload['cancelled_items']);
        $this->assertSame(200.0, (float) $payload['assembled_amount']);
        $this->assertSame($this->collectorId, $payload['picker_id']);
    }

    public function test_picking_finished_ordered_amount_uses_ordered_portions(): void
    {
        // После AssemblyFinish весовая позиция: заказано 3 порции по 100,
        // взвешено меньше → quantity 2. Заказанная сумма — 300, а не 200.
        [, $orderItemId, $assemblyId] = $this->insertOrderWithAssemblyItem();
        \DB::table('order_items')->where('id', $orderItemId)->update(['quantity' => 2, 'ordered_quantity' => 3]);

        AnalyticsEvents::pickingFinished(\App\Models\Assembly::with('order')->find($assemblyId));

        $payload = json_decode($this->latestOutbox('picking.finished')->payload, true);
        $this->assertSame(300.0, (float) $payload['ordered_amount']);
    }

    public function test_picking_finished_ordered_amount_of_replaced_item_is_origin(): void
    {
        // Заказано 3 × 100 исходного товара, собрана замена 2 × 150.
        [, $orderItemId, $assemblyId] = $this->insertOrderWithAssemblyItem();
        \DB::table('order_items')->where('id', $orderItemId)->update([
            'origin_product_id' => \DB::table('order_items')->where('id', $orderItemId)->value('product_id'),
            'origin_price' => 100,
            'origin_quantity' => 3,
            'quantity' => 2,
            'price_during_order' => 150,
        ]);

        AnalyticsEvents::pickingFinished(\App\Models\Assembly::with('order')->find($assemblyId));

        $payload = json_decode($this->latestOutbox('picking.finished')->payload, true);
        $this->assertSame(300.0, (float) $payload['ordered_amount']);
    }

    public function test_order_context_carries_slot_dates_and_amount(): void
    {
        [, , $assemblyId] = $this->insertOrderWithAssemblyItem();

        $assembly = \App\Models\Assembly::with('order')->find($assemblyId);

        AnalyticsEvents::pickingFinished($assembly);

        $payload = json_decode($this->latestOutbox('picking.finished')->payload, true);

        // SLA-поля для агрегатора B2E (#11595): слот заказа и сумма
        $this->assertStringStartsWith('2026-07-15T18:00:00', $payload['slot_start_date']);
        $this->assertStringStartsWith('2026-07-15T20:00:00', $payload['slot_end_date']);
        $this->assertSame(1452.5, $payload['order_amount']);
    }

    public function test_delivery_finished_carries_courier_session_and_client_ids(): void
    {
        // Верификация сквозных ID (#11598/#11643): courier_id + session_id +
        // client_id обязаны присутствовать во всех delivery.* событиях.
        [$orderId] = $this->insertOrderWithAssemblyItem();

        $deliveryId = \DB::table('deliveries')->insertGetId([
            'user_id' => $this->collectorId,
            'finished_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = \App\Models\Order::find($orderId);
        $delivery = \App\Models\Delivery::find($deliveryId);

        AnalyticsEvents::deliveryFinished($delivery, $order, false);

        $row = $this->latestOutbox('delivery.finished');
        $this->assertNotNull($row, 'Событие delivery.finished не попало в outbox');

        $payload = json_decode($row->payload, true);

        $this->assertSame($this->collectorId, $payload['courier_id']);
        $this->assertSame('shift-' . $this->shiftId, $payload['session_id']);
        // client_id: без записи в event_sync_metadata — локальный id клиента docker-БД
        $this->assertSame((string) $this->clientId, $payload['client_id']);
        $this->assertSame('local', $payload['client_id_scope']);
        $this->assertSame($order->uuid, $payload['correlation_id']);
        $this->assertArrayHasKey('order_amount', $payload);
    }

    public function test_item_not_found_publishes_with_reason(): void
    {
        [, , , $assemblyItemId] = $this->insertOrderWithAssemblyItem();

        $reasonId = \DB::table('product_cancel_reasons')->insertGetId([
            'title' => 'Нет на полке',
            'is_active' => 1,
            'position' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $item = AssemblyItem::with(['orderItem.order', 'assembly'])->find($assemblyItemId);

        AnalyticsEvents::itemNotFound($item, $reasonId);

        $row = $this->latestOutbox('picking.item_not_found');
        $this->assertNotNull($row, 'Событие picking.item_not_found не попало в outbox');

        $payload = json_decode($row->payload, true);

        $this->assertSame($reasonId, $payload['cancel_reason_id']);
        $this->assertSame('Нет на полке', $payload['cancel_reason']);
    }
}
