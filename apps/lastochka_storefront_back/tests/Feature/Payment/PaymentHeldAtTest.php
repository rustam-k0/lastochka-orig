<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Actions\Order\CreateOrderAction;
use App\Contracts\PaymentGateway;
use App\DTOs\Order\CreateOrderData;
use App\Enums\OrderStatus;
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
 * Момент холдирования средств клиента (MID-285).
 *
 * В режиме «оформление без слотов» курьерка строит от этой метки окно
 * доставки, которое видят сборщик и курьер, поэтому витрина обязана её
 * зафиксировать и донести до платформы. Черновику метка уезжает вместе с
 * order.created (он публикуется как раз по подтверждению холда), заказу,
 * уехавшему раньше холда, — отдельным событием order_payment_held.
 *
 * Отдельный процесс на тест — требование пакета rabbitmq-events: Eloquent-
 * observer'ы регистрируются один раз на процесс.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PaymentHeldAtTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $fake;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-02 08:00:00', config('app.timezone')));

        $this->fake = new FakePaymentGateway;
        $this->fake->holdStatus = 'pending';
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_draft_order_carries_hold_moment_into_created_event(): void
    {
        $this->useMockMode(true);
        $this->setDraftModule(true);
        [$user, $store] = $this->makeContext();

        $order = Order::findOrFail($this->createCardOrder($user, $store)['orderId']);

        // Черновик доведён до боевого состояния подтверждением холда — метка
        // проставлена тем же шагом.
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertNotNull($order->payment_held_at);
        $this->assertSame('2026-07-02 08:00:00', $order->payment_held_at->format('Y-m-d H:i:s'));

        $created = EventOutbox::query()
            ->where('entity_type', 'orders')
            ->where('entity_id', $order->id)
            ->where('event_type', 'created')
            ->firstOrFail();

        $this->assertNotNull($created->payload['payment_held_at'] ?? null);
        $this->assertSame(
            $order->payment_held_at->toIso8601String(),
            $created->payload['payment_held_at']
        );
    }

    public function test_published_order_gets_hold_moment_by_custom_event(): void
    {
        // Модуль черновиков выключен: заказ уехал в курьерку раньше холда,
        // метку донести может только отдельное событие.
        $this->useMockMode(false);
        $this->setDraftModule(false);
        [$user, $store] = $this->makeContext();

        $order = Order::findOrFail($this->createCardOrder($user, $store)['orderId']);
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertNull($order->payment_held_at);

        $paymentId = $order->paymentTransactions()
            ->where('type', PaymentTransactionType::Hold)
            ->firstOrFail()
            ->yookassa_payment_id;

        Carbon::setTestNow(Carbon::parse('2026-07-02 08:12:00', config('app.timezone')));
        app(PaymentService::class)->handleHoldSucceeded($paymentId);

        $order->refresh();
        $this->assertSame('2026-07-02 08:12:00', $order->payment_held_at->format('Y-m-d H:i:s'));

        $held = EventOutbox::query()
            ->where('entity_type', 'orders')
            ->where('entity_id', $order->id)
            ->where('event_type', 'order_payment_held')
            ->firstOrFail();

        $this->assertSame('payment_held', $held->payload['event_type']);
        $this->assertSame(
            $order->payment_held_at->toIso8601String(),
            $held->payload['payment_held_at']
        );
    }

    public function test_repeated_hold_confirmation_does_not_move_the_moment(): void
    {
        // Ретрай вебхука не должен сдвигать окно доставки вперёд.
        $this->useMockMode(false);
        $this->setDraftModule(false);
        [$user, $store] = $this->makeContext();

        $order = Order::findOrFail($this->createCardOrder($user, $store)['orderId']);
        $paymentId = $order->paymentTransactions()
            ->where('type', PaymentTransactionType::Hold)
            ->firstOrFail()
            ->yookassa_payment_id;

        Carbon::setTestNow(Carbon::parse('2026-07-02 08:12:00', config('app.timezone')));
        app(PaymentService::class)->handleHoldSucceeded($paymentId);

        Carbon::setTestNow(Carbon::parse('2026-07-02 08:40:00', config('app.timezone')));
        app(PaymentService::class)->handleHoldSucceeded($paymentId);

        $this->assertSame('2026-07-02 08:12:00', $order->fresh()->payment_held_at->format('Y-m-d H:i:s'));
    }

    private function useMockMode(bool $enabled): void
    {
        config(['services.yookassa.mock' => $enabled]);

        $this->app->instance(
            PaymentGateway::class,
            $enabled ? new MockPaymentGateway($this->fake) : $this->fake,
        );
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

    private function setDraftModule(bool $enabled): void
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

        app(ModuleConfigService::class)->invalidateCache();
    }
}
