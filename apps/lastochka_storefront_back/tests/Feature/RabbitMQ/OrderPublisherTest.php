<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Actions\Order\CancelOrderAction;
use App\Contracts\PaymentGateway;
use App\DTOs\Payment\PaymentInfo;
use App\Enums\OrderStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderItem;
use App\Models\OrderPromocode;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Payment\PaymentService;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\CustomEventPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OrderPublisherTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_created_event_published(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create(['phone' => '+79991234567']);
        $product = Product::factory()->for($store)->create();

        $order = Order::factory()->for($user)->for($store)->create([
            'subtotal' => 500,
            'discount' => 50,
            'delivery_cost' => 200,
            'total' => 650,
        ]);

        OrderItem::factory()->for($order)->for($product)->create([
            'title' => 'Молоко 3.2%',
            'price' => 89,
            'quantity' => 2,
            'total' => 178,
        ]);

        OrderAddress::factory()->for($order)->create([
            'full_address' => 'Москва, ул. Ленина, д. 10',
            'entrance' => '2',
            'intercom' => '42',
            'floor' => '5',
            'apartment' => '42',
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        // Публикуем событие через CustomEventPublisher
        CustomEventPublisher::dispatch(
            entityType: 'orders',
            model: $order,
            eventType: 'order_created',
            payload: [],
            shopCode: $store->code
        );

        // Проверяем outbox
        $this->assertDatabaseHas('event_outbox', [
            'entity_type' => 'orders',
            'entity_id' => $order->id,
            'event_type' => 'order_created',
        ]);

        $outbox = EventOutbox::where('entity_type', 'orders')
            ->where('entity_id', $order->id)
            ->where('event_type', 'order_created')
            ->first();

        $this->assertNotNull($outbox);

        $payload = $outbox->payload;

        $this->assertEquals('MSK_001', $payload['shop_code']);
        $this->assertEquals('created', $payload['event_type']);
        $this->assertEquals('created', $payload['status']);
        $this->assertCount(1, $payload['items']);
        $this->assertEquals(500.0, $payload['items_subtotal']);
        $this->assertEquals(50.0, $payload['discount_total']);
        $this->assertEquals(200.0, $payload['shipping_total']);
        $this->assertEquals(650.0, $payload['grand_total']);
        $this->assertArrayHasKey('event_id', $payload);
        $this->assertArrayHasKey('event_timestamp', $payload);
        $this->assertEquals('storefront', $payload['source']);
    }

    public function test_order_paid_event_published(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();

        $order = Order::factory()->for($user)->for($store)->create([
            'is_paid' => false,
        ]);

        // order_paid публикует приватный publishOrderPaid, к нему ведёт
        // webhook-путь «холд захвачен на стороне YooKassa» — по нему тест
        // и идёт, как соседний тест возврата идёт через handleRefundSucceeded.
        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Hold,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 650,
            'yookassa_payment_id' => 'pay_hold_paid_1',
        ]);

        $gateway = new FakePaymentGateway;
        $gateway->paymentInfo['pay_hold_paid_1'] = new PaymentInfo(
            paymentId: 'pay_hold_paid_1',
            status: 'succeeded',
            amount: '650',
            currency: 'RUB',
        );
        $this->app->instance(PaymentGateway::class, $gateway);

        app(PaymentService::class)->handlePaymentSucceeded('pay_hold_paid_1');

        $this->assertDatabaseHas('event_outbox', [
            'entity_type' => 'orders',
            'entity_id' => $order->id,
            'event_type' => 'order_paid',
        ]);

        $outbox = EventOutbox::where('entity_type', 'orders')
            ->where('entity_id', $order->id)
            ->where('event_type', 'order_paid')
            ->first();

        $this->assertNotNull($outbox);

        $payload = $outbox->payload;

        $this->assertEquals('paid', $payload['event_type']);
        $this->assertTrue($payload['is_paid']);
        $this->assertArrayHasKey('updated_at', $payload);
    }

    public function test_order_cancelled_event_published(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();
        $product = Product::factory()->for($store)->create(['quantity' => 100]);

        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Pending,
        ]);

        OrderItem::factory()->for($order)->for($product)->create([
            'quantity' => 2,
        ]);

        app(CancelOrderAction::class)->execute($order);

        $this->assertDatabaseHas('event_outbox', [
            'entity_type' => 'orders',
            'entity_id' => $order->id,
            'event_type' => 'order_cancelled',
        ]);

        $outbox = EventOutbox::where('entity_type', 'orders')
            ->where('entity_id', $order->id)
            ->where('event_type', 'order_cancelled')
            ->first();

        $this->assertNotNull($outbox);

        $payload = $outbox->payload;

        $this->assertEquals('cancelled', $payload['event_type']);
        $this->assertEquals('cancelled', $payload['status']);
        $this->assertArrayHasKey('updated_at', $payload);
    }

    public function test_order_status_changed_from_crm(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();

        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Pending,
        ]);

        // Создаём metadata
        EventSyncMetadata::create([
            'entity_type' => 'orders',
            'entity_id' => $order->id,
            'external_id' => 'crm_order_123',
            'source' => 'retailcrm',
            'shop_code' => 'MSK_001',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subMinute(),
        ]);

        // Смена статуса шлёт пуш клиенту, а Messaging биндится на реальный
        // Kreait Factory с credentials из БД — без подмены тест уходит
        // в Firebase и падает на «Unable to create an API client without
        // credentials». Тот же приём, что в NotificationGateTest.
        $firebase = \Mockery::mock(\App\Services\FirebaseNotificationService::class);
        $firebase->shouldReceive('sendToUser')->zeroOrMoreTimes()
            ->andReturn(['success' => 0, 'failure' => 0, 'total' => 0]);
        $this->app->instance(\App\Services\FirebaseNotificationService::class, $firebase);

        // Симулируем обработку события через Transformer
        $transformer = new \App\RabbitMQ\Transformers\OrderEventTransformer;

        $eventData = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'status_changed',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'crm_order_123',
            'source' => 'retailcrm',
            'shop_code' => 'MSK_001',
            'status' => 'delivered',
            'tracking_number' => 'TRACK123',
        ];

        $transformer->processStatusChangedEvent($order, $eventData);

        $order->refresh();
        $this->assertEquals(OrderStatus::Delivered, $order->status);
        $this->assertEquals('TRACK123', $order->tracking_number);
    }

    public function test_items_include_external_product_ids(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();
        $product = Product::factory()->for($store)->create();

        // Создаём metadata для продукта (как будто он пришёл из Bagisto)
        EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $product->id,
            'external_id' => 'bagisto_product_456',
            'source' => 'bagisto',
            'shop_code' => 'MSK_001',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now(),
        ]);

        $order = Order::factory()->for($user)->for($store)->create();
        OrderItem::factory()->for($order)->for($product)->create([
            'title' => 'Тестовый товар',
        ]);

        // Публикуем событие
        CustomEventPublisher::dispatch(
            entityType: 'orders',
            model: $order,
            eventType: 'order_created',
            payload: [],
            shopCode: $store->code
        );

        $outbox = EventOutbox::where('entity_type', 'orders')
            ->where('entity_id', $order->id)
            ->where('event_type', 'order_created')
            ->first();

        $payload = $outbox->payload;
        $item = $payload['items'][0];

        $this->assertEquals('bagisto_product_456', $item['product_id']);
        $this->assertEquals('bagisto', $item['product_source']);
    }

    public function test_item_includes_unit_price_old(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();
        $product = Product::factory()->for($store)->create();

        $order = Order::factory()->for($user)->for($store)->create();
        // Позиция со скидкой: оригинал 300, продано за 240.
        OrderItem::factory()->for($order)->for($product)->create([
            'price' => 240,
            'price_old' => 300,
        ]);

        CustomEventPublisher::dispatch(
            entityType: 'orders',
            model: $order,
            eventType: 'order_created',
            payload: [],
            shopCode: $store->code
        );

        $payload = EventOutbox::where('entity_type', 'orders')
            ->where('entity_id', $order->id)
            ->where('event_type', 'order_created')
            ->first()->payload;

        $this->assertEquals(300.0, $payload['items'][0]['unit_price_old']);
    }

    public function test_item_unit_price_old_null_without_discount(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();
        $product = Product::factory()->for($store)->create();

        $order = Order::factory()->for($user)->for($store)->create();
        OrderItem::factory()->for($order)->for($product)->create([
            'price' => 300,
            'price_old' => null,
        ]);

        CustomEventPublisher::dispatch(
            entityType: 'orders',
            model: $order,
            eventType: 'order_created',
            payload: [],
            shopCode: $store->code
        );

        $payload = EventOutbox::where('entity_type', 'orders')
            ->where('entity_id', $order->id)
            ->where('event_type', 'order_created')
            ->first()->payload;

        $this->assertArrayHasKey('unit_price_old', $payload['items'][0]);
        $this->assertNull($payload['items'][0]['unit_price_old']);
    }

    public function test_order_payload_includes_address(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();

        $order = Order::factory()->for($user)->for($store)->create();

        OrderAddress::factory()->for($order)->create([
            'full_address' => 'Москва, Тверская, д.1',
            'entrance' => '3',
            'intercom' => '123',
            'floor' => '7',
            'apartment' => '99',
            'latitude' => 55.1234,
            'longitude' => 37.5678,
        ]);

        CustomEventPublisher::dispatch(
            entityType: 'orders',
            model: $order,
            eventType: 'order_created',
            payload: [],
            shopCode: $store->code
        );

        $outbox = EventOutbox::where('entity_type', 'orders')
            ->where('entity_id', $order->id)
            ->where('event_type', 'order_created')
            ->first();

        $payload = $outbox->payload;
        $address = $payload['address'];

        $this->assertEquals('Москва, Тверская, д.1', $address['address']);
        $this->assertEquals('3', $address['entrance']);
        $this->assertEquals('123', $address['intercom']);
        $this->assertEquals('7', $address['floor']);
        $this->assertEquals('99', $address['flat']);
        $this->assertEquals(55.1234, $address['latitude']);
        $this->assertEquals(37.5678, $address['longitude']);
    }

    public function test_order_payload_includes_promocode(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();

        $order = Order::factory()->for($user)->for($store)->create([
            'discount' => 50,
        ]);

        OrderPromocode::factory()->for($order)->create([
            'code' => 'SALE10',
            'type' => \App\Enums\PromocodeType::Percent,
            'value' => 10,
            'discount_amount' => 50,
        ]);

        CustomEventPublisher::dispatch(
            entityType: 'orders',
            model: $order,
            eventType: 'order_created',
            payload: [],
            shopCode: $store->code
        );

        $outbox = EventOutbox::where('entity_type', 'orders')
            ->where('entity_id', $order->id)
            ->where('event_type', 'order_created')
            ->first();

        $payload = $outbox->payload;
        $promocode = $payload['promocode'];

        $this->assertEquals('SALE10', $promocode['code']);
        $this->assertEquals(10, $promocode['discount_percent']);
    }

    public function test_order_payload_includes_client_comment(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();

        $order = Order::factory()->for($user)->for($store)->create([
            'comment' => 'Позвоните за 10 минут',
        ]);

        CustomEventPublisher::dispatch(
            entityType: 'orders',
            model: $order,
            eventType: 'order_created',
            payload: [],
            shopCode: $store->code
        );

        $outbox = EventOutbox::where('entity_type', 'orders')
            ->where('entity_id', $order->id)
            ->where('event_type', 'order_created')
            ->first();

        $payload = $outbox->payload;

        $this->assertEquals('Позвоните за 10 минут', $payload['client_comment']);
    }

    public function test_order_payload_client_comment_null_when_empty(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();

        $order = Order::factory()->for($user)->for($store)->create([
            'comment' => null,
        ]);

        CustomEventPublisher::dispatch(
            entityType: 'orders',
            model: $order,
            eventType: 'order_created',
            payload: [],
            shopCode: $store->code
        );

        $outbox = EventOutbox::where('entity_type', 'orders')
            ->where('entity_id', $order->id)
            ->where('event_type', 'order_created')
            ->first();

        $payload = $outbox->payload;

        $this->assertArrayHasKey('client_comment', $payload);
        $this->assertNull($payload['client_comment']);
    }

    public function test_order_routing_key_uses_order_prefix(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();

        // Observer создаёт событие автоматически при Order::create()
        $order = Order::factory()->for($user)->for($store)->create();

        $outbox = EventOutbox::where('entity_type', 'orders')
            ->where('entity_id', $order->id)
            ->where('event_type', 'created')
            ->first();

        $this->assertNotNull($outbox);
        $this->assertEquals('order.created', $outbox->routing_key);
    }

    public function test_order_payment_failed_event_published_on_hold_canceled(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->for($store)->create(['is_paid' => false]);

        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Hold,
            'status' => PaymentTransactionStatus::Pending,
            'amount' => 500,
            'yookassa_payment_id' => 'pay_hold_1',
        ]);

        app(PaymentService::class)->handleHoldCanceled('pay_hold_1', 'insufficient_funds');

        $this->assertDatabaseHas('event_outbox', [
            'entity_type' => 'orders',
            'entity_id' => $order->id,
            'event_type' => 'order_payment_failed',
        ]);

        $outbox = EventOutbox::where('entity_type', 'orders')
            ->where('entity_id', $order->id)
            ->where('event_type', 'order_payment_failed')
            ->first();

        $this->assertNotNull($outbox);

        $payload = $outbox->payload;

        $this->assertEquals('payment_failed', $payload['event_type']);
        $this->assertEquals('failed', $payload['payment_status']);
        $this->assertArrayHasKey('updated_at', $payload);
    }

    public function test_no_event_published_when_hold_canceled_transaction_not_found(): void
    {
        // Неизвестный yookassa_payment_id — транзакция не найдена, ничего не публикуем.
        app(PaymentService::class)->handleHoldCanceled('unknown_payment_id');

        $this->assertDatabaseMissing('event_outbox', [
            'event_type' => 'order_payment_failed',
        ]);
    }

    public function test_order_refunded_event_published_on_refund_succeeded(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->for($store)->create([
            'is_paid' => true,
            'captured_amount' => 500,
            'refunded_amount' => 0,
        ]);

        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 500,
            'yookassa_payment_id' => 'pay_captured_1',
        ]);

        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Refund,
            'status' => PaymentTransactionStatus::Pending,
            'amount' => 500,
            'yookassa_payment_id' => 'pay_captured_1',
            'yookassa_refund_id' => 'refund_1',
        ]);

        app(PaymentService::class)->handleRefundSucceeded('refund_1', 'pay_captured_1', 500.0);

        $this->assertDatabaseHas('event_outbox', [
            'entity_type' => 'orders',
            'entity_id' => $order->id,
            'event_type' => 'order_refunded',
        ]);

        $outbox = EventOutbox::where('entity_type', 'orders')
            ->where('entity_id', $order->id)
            ->where('event_type', 'order_refunded')
            ->first();

        $this->assertNotNull($outbox);

        $payload = $outbox->payload;

        $this->assertEquals('refunded', $payload['event_type']);
        $this->assertEquals('refunded', $payload['payment_status']);
        $this->assertEquals(500.0, $payload['refunded_amount']);

        $order->refresh();
        $this->assertEquals(500.0, (float) $order->refunded_amount);
    }
}
