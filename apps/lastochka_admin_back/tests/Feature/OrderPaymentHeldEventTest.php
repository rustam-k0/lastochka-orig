<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Момент холдирования средств в общей БД (MID-285).
 *
 * От метки курьерка строит окно доставки для сборщика и курьера в режиме
 * «оформление без слотов». Приезжает она двумя путями: полем payload у
 * order.created и кастомным событием order.order_payment_held по заказу,
 * который уехал в курьерку раньше подтверждения холда.
 */
class OrderPaymentHeldEventTest extends TestCase
{
    use DatabaseTransactions;

    private int $shopId;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopId = \DB::table('shops')->insertGetId([
            'title' => 'Held shop', 'id_1c' => random_int(900000, 999999), 'default' => 1,
            'slot_max_orders' => 10, 'slot_time' => 60, 'is_popular' => 0,
            'is_pickup_enabled' => 0, 'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 0, 'is_bonus_enabled' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->clientId = \DB::table('clients')->insertGetId([
            'password' => 'test', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeOrder(?string $heldAt = null): Order
    {
        $orderId = \DB::table('orders')->insertGetId([
            'shop_id' => $this->shopId, 'client_id' => $this->clientId, 'uuid' => 'held-' . uniqid(),
            'discount' => 0, 'payment_type_id' => 1, 'paid' => 0, 'type_id' => 2,
            'status_id' => 1, 'amount' => 1500,
            'slot_start_date' => now(), 'slot_end_date' => now(),
            'payment_held_at' => $heldAt,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Order::findOrFail($orderId);
    }

    public function test_payment_held_event_stores_hold_moment(): void
    {
        $order = $this->makeOrder();

        $heldAt = \Carbon\Carbon::parse('2026-08-21 18:17:00', config('app.timezone'));

        app(OrderEventTransformer::class)->processPaymentHeldEvent($order, [
            'event_id' => 'test-event',
            'event_type' => 'payment_held',
            'payment_held_at' => $heldAt->toIso8601String(),
        ]);

        $this->assertSame(
            '2026-08-21 18:17:00',
            Order::find($order->id)->payment_held_at->format('Y-m-d H:i:s')
        );
    }

    public function test_payment_held_event_does_not_move_existing_moment(): void
    {
        // Ретрай события не должен сдвигать окно доставки вперёд
        $order = $this->makeOrder('2026-08-21 18:17:00');

        app(OrderEventTransformer::class)->processPaymentHeldEvent($order, [
            'event_id' => 'test-event',
            'payment_held_at' => \Carbon\Carbon::parse('2026-08-21 19:40:00', config('app.timezone'))->toIso8601String(),
        ]);

        $this->assertSame(
            '2026-08-21 18:17:00',
            Order::find($order->id)->payment_held_at->format('Y-m-d H:i:s')
        );
    }

    public function test_payment_held_event_without_payload_field_keeps_null(): void
    {
        $order = $this->makeOrder();

        app(OrderEventTransformer::class)->processPaymentHeldEvent($order, [
            'event_id' => 'test-event',
        ]);

        $this->assertNull(Order::find($order->id)->payment_held_at);
    }

    public function test_from_event_maps_hold_moment(): void
    {
        $attributes = app(OrderEventTransformer::class)->fromEvent([
            'id' => 'ext-' . uniqid(),
            'shop_code' => 'unknown-shop',
            'status' => 'created',
            'payment_type' => 'card',
            'shipping_method' => 'courier',
            'grand_total' => 1500,
            'client_id' => '1',
            'payment_held_at' => \Carbon\Carbon::parse('2026-08-21 18:17:00', config('app.timezone'))->toIso8601String(),
        ]);

        $this->assertSame(
            '2026-08-21 18:17:00',
            $attributes['payment_held_at']->format('Y-m-d H:i:s')
        );
    }

    public function test_hold_moment_is_normalized_to_app_timezone(): void
    {
        // Издатель может прислать метку с чужим офсетом, а колонка таймзону
        // не хранит — без нормализации в неё легло бы стенное время чужой зоны
        $order = $this->makeOrder();

        $foreign = \Carbon\Carbon::parse('2026-08-21 18:17:00', config('app.timezone'))
            ->setTimezone('UTC')
            ->toIso8601String();

        app(OrderEventTransformer::class)->processPaymentHeldEvent($order, [
            'event_id' => 'test-event',
            'payment_held_at' => $foreign,
        ]);

        $this->assertSame(
            '2026-08-21 18:17:00',
            Order::find($order->id)->payment_held_at->format('Y-m-d H:i:s')
        );
    }

    public function test_from_event_without_field_does_not_touch_hold_moment(): void
    {
        // Сервисы общей БД поля не присылают — оно не должно затираться
        $attributes = app(OrderEventTransformer::class)->fromEvent([
            'id' => 'ext-' . uniqid(),
            'shop_code' => 'unknown-shop',
            'status' => 'created',
            'payment_type' => 'card',
            'shipping_method' => 'courier',
            'grand_total' => 1500,
            'client_id' => '1',
        ]);

        $this->assertArrayNotHasKey('payment_held_at', $attributes);
    }
}
