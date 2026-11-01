<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Actions\Order\CreateOrderAction;
use App\Contracts\PaymentGateway;
use App\DTOs\Order\CreateOrderData;
use App\DTOs\Payment\PaymentInfo;
use App\Enums\OrderStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\ShippingMethod;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartPromocode;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promocode;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\Payment\PaymentService;
use App\Services\Pickup\PickupSlotService;
use App\Services\Settings\ModuleConfigService;
use Carbon\Carbon;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

/**
 * Модуль draft_orders_until_payment: при онлайн-оплате новой картой заказ
 * создаётся черновиком и становится настоящим только после подтверждения холда.
 *
 * Проверяется главный сценарий-жалоба: клиент ушёл со страницы ЮKassa, ничего
 * не оплатив — корзина обязана остаться на месте, а заказ не должен попасть ни
 * в историю клиента, ни в работу курьерки.
 *
 * Отдельный процесс на тест — требование пакета rabbitmq-events: Eloquent-
 * observer'ы регистрируются один раз на процесс (static $observersRegistered),
 * и во втором тесте того же процесса слушатели висели бы на уничтоженном
 * диспетчере, а проверки event_outbox молча проходили бы вхолостую.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DraftOrderUntilPaymentTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        // Слоты самовывоза считаются от now(); поздним вечером список пуст.
        Carbon::setTestNow(Carbon::parse('2026-07-02 08:00:00', config('app.timezone')));

        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_card_order_is_created_as_draft_and_keeps_cart(): void
    {
        $this->setDraftModule(true);
        [$user, $store, $product] = $this->makeContext();

        $result = $this->createCardOrder($user, $store);

        $order = Order::findOrFail($result['orderId']);

        $this->assertSame(OrderStatus::Draft, $order->status);
        $this->assertNotNull($order->awaiting_payment_until);
        $this->assertNotNull($result['confirmationUrl'], 'клиент всё так же получает ссылку на оплату');

        // Корзина не тронута — клиент, закрывший страницу оплаты, вернётся к товарам.
        $this->assertDatabaseCount('cart_items', 1);

        // Платформа о заказе не знает.
        $this->assertFalse($this->hasOutboxEvent($order->id));

        // Товар зарезервирован — слот и остаток держатся на время оплаты.
        $this->assertSame(9, $product->fresh()->quantity);
    }

    public function test_module_disabled_keeps_previous_behaviour(): void
    {
        $this->setDraftModule(false);
        [$user, $store] = $this->makeContext();

        $order = Order::findOrFail($this->createCardOrder($user, $store)['orderId']);

        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertNull($order->awaiting_payment_until);
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertTrue($this->hasOutboxEvent($order->id));
    }

    public function test_cash_order_is_never_draft(): void
    {
        $this->setDraftModule(true);
        [$user, $store] = $this->makeContext();

        $result = app(CreateOrderAction::class)->execute(new CreateOrderData(
            user: $user,
            storeId: $store->id,
            address: null,
            deliverySlotId: $this->pickupSlotId($store),
            shippingMethod: ShippingMethod::Pickup,
            paymentType: 'cash',
        ));

        $order = Order::findOrFail($result['orderId']);

        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertTrue($this->hasOutboxEvent($order->id));
    }

    public function test_hold_webhook_finalizes_draft(): void
    {
        $this->setDraftModule(true);
        [$user, $store] = $this->makeContext();

        $order = Order::findOrFail($this->createCardOrder($user, $store)['orderId']);
        $paymentId = $this->holdPaymentId($order);

        app(PaymentService::class)->handleHoldSucceeded($paymentId);

        $order->refresh();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertNull($order->awaiting_payment_until);
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertTrue($this->hasOutboxEvent($order->id), 'заказ уезжает в курьерку только после оплаты');
    }

    public function test_hold_canceled_webhook_closes_draft_and_returns_stock(): void
    {
        $this->setDraftModule(true);
        [$user, $store, $product] = $this->makeContext();

        $order = Order::findOrFail($this->createCardOrder($user, $store)['orderId']);
        $paymentId = $this->holdPaymentId($order);

        app(PaymentService::class)->handleHoldCanceled($paymentId, 'canceled_by_merchant');

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(10, $product->fresh()->quantity, 'товар вернулся в остаток');
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertFalse($this->hasOutboxEvent($order->id), 'платформе нечего отменять — она о заказе не знала');
    }

    public function test_expired_draft_is_cancelled_by_command(): void
    {
        $this->setDraftModule(true);
        [$user, $store, $product] = $this->makeContext();

        $order = Order::findOrFail($this->createCardOrder($user, $store)['orderId']);
        $this->gateway->paymentInfo[$this->holdPaymentId($order)] = new PaymentInfo(
            paymentId: $this->holdPaymentId($order),
            status: 'pending',
            amount: '500.00',
            currency: 'RUB',
        );

        Carbon::setTestNow(Carbon::getTestNow()->addMinutes(16));

        $this->artisan('orders:cancel-unpaid')->assertSuccessful();

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(10, $product->fresh()->quantity);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_expired_draft_with_confirmed_hold_is_finalized_not_cancelled(): void
    {
        $this->setDraftModule(true);
        [$user, $store] = $this->makeContext();

        $order = Order::findOrFail($this->createCardOrder($user, $store)['orderId']);
        $paymentId = $this->holdPaymentId($order);

        // Вебхук не доехал, но деньги реально захолдированы — отменять нельзя.
        $this->gateway->paymentInfo[$paymentId] = new PaymentInfo(
            paymentId: $paymentId,
            status: 'waiting_for_capture',
            amount: '500.00',
            currency: 'RUB',
        );

        Carbon::setTestNow(Carbon::getTestNow()->addMinutes(16));

        $this->artisan('orders:cancel-unpaid')->assertSuccessful();

        $order->refresh();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertTrue($this->hasOutboxEvent($order->id));
    }

    public function test_promocode_usage_is_charged_only_after_payment(): void
    {
        $this->setDraftModule(true);
        [$user, $store] = $this->makeContext();

        $promocode = Promocode::factory()->create([
            'code' => 'SALE10',
            'type' => 'percent',
            'value' => 10,
            'used_count' => 0,
            'is_active' => true,
        ]);
        $cart = Cart::where('user_id', $user->id)->firstOrFail();
        CartPromocode::create([
            'cart_id' => $cart->id,
            'promocode_id' => $promocode->id,
            'store_id' => $store->id,
        ]);

        $order = Order::findOrFail($this->createCardOrder($user, $store)['orderId']);

        $this->assertDatabaseCount('promocode_usages', 0);
        $this->assertSame(0, $promocode->fresh()->used_count);

        app(PaymentService::class)->handleHoldSucceeded($this->holdPaymentId($order));

        $this->assertDatabaseCount('promocode_usages', 1);
        $this->assertSame(1, $promocode->fresh()->used_count);
    }

    public function test_draft_is_hidden_from_client_order_list(): void
    {
        $this->setDraftModule(true);
        [$user, $store] = $this->makeContext();

        $order = Order::findOrFail($this->createCardOrder($user, $store)['orderId']);

        $this->actingAs($user)
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('total', 0);

        $this->actingAs($user)
            ->getJson('/api/v1/orders/active')
            ->assertOk()
            ->assertJsonPath('totalActive', 0);

        app(PaymentService::class)->handleHoldSucceeded($this->holdPaymentId($order));

        $this->actingAs($user)
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_payment_status_endpoint_reflects_gateway_state(): void
    {
        $this->setDraftModule(true);
        [$user, $store] = $this->makeContext();

        $order = Order::findOrFail($this->createCardOrder($user, $store)['orderId']);
        $paymentId = $this->holdPaymentId($order);

        $this->gateway->paymentInfo[$paymentId] = new PaymentInfo(
            paymentId: $paymentId,
            status: 'pending',
            amount: '500.00',
            currency: 'RUB',
        );

        $this->actingAs($user)
            ->getJson("/api/v1/orders/{$order->id}/payment-status")
            ->assertOk()
            ->assertJsonPath('paymentState', 'awaiting')
            ->assertJsonPath('status', 'draft');

        // Клиент вернулся раньше вебхука: поллинг сам догоняет состояние шлюза.
        $this->gateway->paymentInfo[$paymentId] = new PaymentInfo(
            paymentId: $paymentId,
            status: 'waiting_for_capture',
            amount: '500.00',
            currency: 'RUB',
        );

        $this->actingAs($user)
            ->getJson("/api/v1/orders/{$order->id}/payment-status")
            ->assertOk()
            ->assertJsonPath('paymentState', 'confirmed')
            ->assertJsonPath('status', 'pending');

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_client_can_cancel_draft_without_notifying_platform(): void
    {
        $this->setDraftModule(true);
        [$user, $store, $product] = $this->makeContext();

        $order = Order::findOrFail($this->createCardOrder($user, $store)['orderId']);

        $this->actingAs($user)
            ->postJson("/api/v1/orders/{$order->id}/cancel")
            ->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(10, $product->fresh()->quantity);
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertFalse($this->hasOutboxEvent($order->id));
    }

    /**
     * @return array{0: User, 1: Store, 2: Product}
     */
    private function makeContext(): array
    {
        $user = User::factory()->create();

        $store = Store::factory()->create([
            'is_pickup_enabled' => true,
            'is_open_24_7' => true,
            'slot_duration' => 60,
            'min_pickup_order_amount' => 0,
            'pickup_payment_methods' => ['cash', 'card'],
        ]);

        $product = Product::factory()->for($store)->create([
            'price' => 500,
            'quantity' => 10,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
            // fixed — остаток ведёт витрина; при normal резервирование
            // намеренно не трогает quantity (им управляет 1С).
            'stock_mode' => 'fixed',
        ]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 500,
        ]);

        return [$user, $store, $product];
    }

    /**
     * @return array{orderId: int, confirmationUrl: string|null}
     */
    private function createCardOrder(User $user, Store $store): array
    {
        return app(CreateOrderAction::class)->execute(new CreateOrderData(
            user: $user,
            storeId: $store->id,
            address: null,
            deliverySlotId: $this->pickupSlotId($store),
            shippingMethod: ShippingMethod::Pickup,
            paymentType: 'card',
        ));
    }

    private function pickupSlotId(Store $store): int
    {
        $slots = app(PickupSlotService::class)->getSlots($store, now()->format('Y-m-d'), null);
        $this->assertNotEmpty($slots, 'у круглосуточного магазина должен быть слот самовывоза');

        return $slots[0]['id'];
    }

    private function holdPaymentId(Order $order): string
    {
        $hold = $order->paymentTransactions()
            ->where('type', PaymentTransactionType::Hold)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(PaymentTransactionStatus::Pending, $hold->status);

        return (string) $hold->yookassa_payment_id;
    }

    private function hasOutboxEvent(int $orderId): bool
    {
        return EventOutbox::query()
            ->where('entity_type', 'orders')
            ->where('entity_id', $orderId)
            ->exists();
    }

    private function setDraftModule(bool $enabled, int $ttlMinutes = 15): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    ModuleConfigService::DRAFT_ORDERS_UNTIL_PAYMENT_KEY => [
                        'label' => 'Заказ только после оплаты',
                        'enabled' => $enabled,
                    ],
                ], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ]
        );

        StorefrontSetting::updateOrCreate(
            ['key' => 'draft_order_ttl_minutes'],
            ['value' => (string) $ttlMinutes, 'type' => 'string']
        );

        app(ModuleConfigService::class)->invalidateCache();
    }
}
