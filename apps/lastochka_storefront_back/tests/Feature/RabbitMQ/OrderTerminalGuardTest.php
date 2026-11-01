<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Enums\OrderStatus;
use App\Jobs\SendOrderStatusNotification;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * LASTOCHKI-JAMI-9665: гарантия что terminal-статус (Cancelled/Delivered)
 * не перезаписывается входящим order.updated событием.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OrderTerminalGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancelled_order_blocks_incoming_assembling_status(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Cancelled,
        ]);

        $transformer = new OrderEventTransformer;

        $eventData = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'event_timestamp' => now()->toIso8601String(),
            'status' => 'assembling',
        ];
        $transformedData = [
            'status' => OrderStatus::Assembling,
            'tracking_number' => null,
        ];

        $result = $transformer->beforeUpdate($order, $eventData, $transformedData);

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('status', $result, 'Status должен быть удалён из transformedData для отменённого заказа');
        $this->assertArrayHasKey('tracking_number', $result, 'Другие поля должны остаться');
    }

    public function test_delivered_order_blocks_incoming_assembled_status(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Delivered,
        ]);

        $transformer = new OrderEventTransformer;

        $eventData = ['event_id' => Str::uuid()->toString(), 'event_type' => 'updated'];
        $transformedData = ['status' => OrderStatus::Assembled];

        $result = $transformer->beforeUpdate($order, $eventData, $transformedData);

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('status', $result);
    }

    public function test_non_terminal_order_allows_status_change(): void
    {
        Bus::fake([SendOrderStatusNotification::class]);

        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Pending,
        ]);

        $transformer = new OrderEventTransformer;

        $eventData = ['event_id' => Str::uuid()->toString(), 'event_type' => 'updated'];
        $transformedData = ['status' => OrderStatus::Assembling];

        $result = $transformer->beforeUpdate($order, $eventData, $transformedData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result, 'Для не-terminal заказа status должен остаться');
        $this->assertEquals(OrderStatus::Assembling, $result['status']);
    }

    public function test_terminal_with_same_status_is_not_blocked(): void
    {
        // Если incoming status совпадает с local (идемпотент), guard не должен срабатывать
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Cancelled,
        ]);

        $transformer = new OrderEventTransformer;

        $eventData = ['event_id' => Str::uuid()->toString(), 'event_type' => 'updated'];
        $transformedData = ['status' => OrderStatus::Cancelled];

        $result = $transformer->beforeUpdate($order, $eventData, $transformedData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
        $this->assertEquals(OrderStatus::Cancelled, $result['status']);
    }

    /**
     * Для terminal-заказа shipping-setup поля (shipping_method, shipping_tariff_code,
     * pickup_external_id, pickup_address_data) должны быть заблокированы.
     * Трек-номера (cdek_number, cdek_status, rp_barcode, rp_status) — разрешены.
     */
    public function test_terminal_order_blocks_shipping_setup_fields_but_allows_tracking(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Cancelled,
        ]);

        $transformer = new OrderEventTransformer;

        $eventData = ['event_id' => Str::uuid()->toString(), 'event_type' => 'updated'];
        $transformedData = [
            // shipping-setup поля — должны быть удалены для terminal
            'shipping_method' => 'cdek',
            'shipping_tariff_code' => 'CDEK_TARIFF_001',
            'pickup_external_id' => 'pickup-ext-id',
            'pickup_address_data' => ['city' => 'Moscow'],
            // трек-номера — должны остаться
            'cdek_number' => '1234567890',
            'cdek_status' => 'DELIVERED',
            'rp_barcode' => 'RP123',
            'rp_status' => 'delivered',
        ];

        $result = $transformer->beforeUpdate($order, $eventData, $transformedData);

        $this->assertIsArray($result);

        // Shipping-setup заблокированы
        $this->assertArrayNotHasKey('shipping_method', $result);
        $this->assertArrayNotHasKey('shipping_tariff_code', $result);
        $this->assertArrayNotHasKey('pickup_external_id', $result);
        $this->assertArrayNotHasKey('pickup_address_data', $result);

        // Трек-номера разрешены
        $this->assertArrayHasKey('cdek_number', $result);
        $this->assertEquals('1234567890', $result['cdek_number']);
        $this->assertArrayHasKey('cdek_status', $result);
        $this->assertArrayHasKey('rp_barcode', $result);
        $this->assertArrayHasKey('rp_status', $result);
    }

    /**
     * fromEvent: conditional set — поля доставки попадают в атрибуты только если
     * переданы в payload (array_key_exists, а не isset, чтобы корректно работал null).
     */
    public function test_from_event_includes_delivery_fields_when_present(): void
    {
        $transformer = new OrderEventTransformer;

        $data = [
            'event_id' => Str::uuid()->toString(),
            'source' => 'storefront',
            'status' => 'assembling',
            'shipping_method' => 'cdek',
            'shipping_tariff_code' => 'CDEK_500',
            'pickup_external_id' => 'pvz-001',
            'pickup_address_data' => ['city' => 'Moscow', 'address' => 'Ул. Ленина 1'],
            'cdek_number' => '1234567890',
            'cdek_status' => 'IN_TRANSIT',
            'rp_barcode' => null,  // явный null — должен попасть как null
            'rp_status' => null,
        ];

        $result = $transformer->fromEvent($data);

        $this->assertArrayHasKey('shipping_method', $result);
        $this->assertEquals('cdek', $result['shipping_method']);
        $this->assertArrayHasKey('shipping_tariff_code', $result);
        $this->assertEquals('CDEK_500', $result['shipping_tariff_code']);
        $this->assertArrayHasKey('pickup_external_id', $result);
        $this->assertArrayHasKey('pickup_address_data', $result);
        $this->assertArrayHasKey('cdek_number', $result);
        $this->assertArrayHasKey('cdek_status', $result);
        // null-значения тоже должны присутствовать (array_key_exists, не isset)
        $this->assertArrayHasKey('rp_barcode', $result);
        $this->assertNull($result['rp_barcode']);
        $this->assertArrayHasKey('rp_status', $result);
    }

    /**
     * fromEvent: если поля доставки отсутствуют в payload — они не попадают в атрибуты
     * (partial-update не занулит локальные значения).
     */
    public function test_from_event_excludes_delivery_fields_when_absent(): void
    {
        $transformer = new OrderEventTransformer;

        $data = [
            'event_id' => Str::uuid()->toString(),
            'source' => 'storefront',
            'status' => 'assembling',
            // delivery fields отсутствуют полностью
        ];

        $result = $transformer->fromEvent($data);

        $this->assertArrayNotHasKey('shipping_method', $result);
        $this->assertArrayNotHasKey('shipping_tariff_code', $result);
        $this->assertArrayNotHasKey('pickup_external_id', $result);
        $this->assertArrayNotHasKey('pickup_address_data', $result);
        $this->assertArrayNotHasKey('cdek_number', $result);
        $this->assertArrayNotHasKey('cdek_status', $result);
        $this->assertArrayNotHasKey('rp_barcode', $result);
        $this->assertArrayNotHasKey('rp_status', $result);
    }
}
