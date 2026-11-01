<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Actions\Order\CreateOrderAction;
use App\Contracts\PaymentGateway;
use App\DTOs\Order\CreateOrderData;
use App\Enums\OrderStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\ShippingMethod;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\Payment\MockPaymentGateway;
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
 * Оформление заказа картой на стенде с mock-режимом (YOOKASSA_MOCK_MODE).
 *
 * Сценарий, ради которого режим и делался: вебхука на стенде нет, поэтому
 * подтверждать холд некому. Без mock'а заказ навсегда остаётся черновиком
 * (модуль draft_orders_until_payment) и через TTL отменяется кроном, хотя
 * платёж в тестовой ЮKassa прошёл.
 *
 * Отдельный процесс на тест — требование пакета rabbitmq-events: Eloquent-
 * observer'ы регистрируются один раз на процесс, иначе проверки event_outbox
 * во втором тесте проходили бы вхолостую.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class YooKassaMockCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $fake;

    protected function setUp(): void
    {
        parent::setUp();

        // Слоты самовывоза считаются от now(); поздним вечером список пуст.
        Carbon::setTestNow(Carbon::parse('2026-07-02 08:00:00', config('app.timezone')));

        $this->fake = new FakePaymentGateway;
        // Реальный redirect-флоу: ЮKassa отдаёт платёж в pending и ждёт,
        // пока клиент введёт карту на своей форме.
        $this->fake->holdStatus = 'pending';
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function useMockMode(bool $enabled): void
    {
        config(['services.yookassa.mock' => $enabled]);

        $this->app->instance(
            PaymentGateway::class,
            $enabled ? new MockPaymentGateway($this->fake) : $this->fake,
        );
    }

    public function test_card_order_is_paid_immediately_without_webhook(): void
    {
        $this->useMockMode(true);
        $this->setDraftModule(true);
        [$user, $store, $product] = $this->makeContext();

        $result = $this->createCardOrder($user, $store);
        $order = Order::findOrFail($result['orderId']);

        // Черновик доведён до боевого состояния прямо в момент создания холда.
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertNull($order->awaiting_payment_until);
        $this->assertSame(500.0, (float) $order->held_amount);

        $hold = $order->paymentTransactions()
            ->where('type', PaymentTransactionType::Hold)
            ->firstOrFail();
        $this->assertSame(PaymentTransactionStatus::Succeeded, $hold->status);

        // Клиент всё так же получает форму оплаты ЮKassa.
        $this->assertNotNull($result['confirmationUrl']);

        // Отложенное сделано: корзина очищена, заказ уехал в курьерку.
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertTrue($this->hasOutboxEvent($order->id));

        $this->assertSame(9, $product->fresh()->quantity);
    }

    public function test_card_order_waits_for_webhook_when_mock_disabled(): void
    {
        $this->useMockMode(false);
        $this->setDraftModule(true);
        [$user, $store] = $this->makeContext();

        $order = Order::findOrFail($this->createCardOrder($user, $store)['orderId']);

        $this->assertSame(OrderStatus::Draft, $order->status);
        $this->assertNotNull($order->awaiting_payment_until);
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
