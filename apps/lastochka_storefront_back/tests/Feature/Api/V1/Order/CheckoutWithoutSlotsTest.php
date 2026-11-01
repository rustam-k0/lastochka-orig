<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Order;

use App\Actions\Order\CreateClientOrderAction;
use App\Contracts\PaymentGateway;
use App\DTOs\Order\CreateClientOrderData;
use App\DTOs\Payment\PaymentResult;
use App\Enums\ShippingMethod;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\IntegrationConfig;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\StoreSchedule;
use App\Models\User;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use App\Services\Delivery\Contracts\DeliveryClientInterface;
use App\Services\Delivery\FakeDeliveryClient;
use App\Services\Settings\ModuleConfigService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Модуль «Оформление заказа без слотов» (checkout_without_slots, MID-256).
 *
 * При включённом флаге клиент может оформить заказ без deliverySlotId —
 * доставка «в ближайшее время» (у заказчика 15–30 минут от оформления).
 * Слот в заказе = NULL, событие order.created уходит с slot_* = null.
 * При выключенном флаге поведение прежнее (слот обязателен — см.
 * CdekRussianPostOrderTest::test_courier_order_still_requires_delivery_slot).
 */
final class CheckoutWithoutSlotsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Среда: 2026-07-02 (четверг), 12:00 — рабочее время для тестов графика.
        Carbon::setTestNow(Carbon::parse('2026-07-02 12:00:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ==================== POST /api/v1/orders ====================

    public function test_courier_order_without_slot_is_created_when_module_enabled(): void
    {
        $this->enableCheckoutWithoutSlots();
        [$user, $store, $address] = $this->courierFixture();

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'shippingMethod' => 'courier',
            // deliverySlotId не передан — заказ «на ближайшее время»
        ]);

        $response->assertCreated()->assertJsonStructure(['orderId']);

        $order = Order::findOrFail($response->json('orderId'));
        $this->assertNull($order->delivery_slot_start);
        $this->assertNull($order->delivery_slot_end);
        $this->assertNull($order->delivery_slot_display);
        $this->assertSame(ShippingMethod::Courier, $order->shipping_method);
        // Стоимость доставки по-прежнему берётся из зоны slots-api
        // (FakeDeliveryClient: 200 ₽, бесплатно от 2000; subtotal 600 → 200)
        $this->assertSame(200.0, (float) $order->delivery_cost);
    }

    public function test_courier_order_with_slot_is_still_honored_when_module_enabled(): void
    {
        $this->enableCheckoutWithoutSlots();
        [$user, $store, $address] = $this->courierFixture();
        $slotId = $this->getValidDeliverySlotId();

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'shippingMethod' => 'courier',
            'deliverySlotId' => $slotId,
        ]);

        $response->assertCreated();

        $order = Order::findOrFail($response->json('orderId'));
        $this->assertSame($slotId, $order->delivery_slot_start->timestamp);
        $this->assertNotNull($order->delivery_slot_display);
    }

    public function test_courier_order_with_invalid_slot_is_rejected_when_module_enabled(): void
    {
        $this->enableCheckoutWithoutSlots();
        [$user, $store, $address] = $this->courierFixture();

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'shippingMethod' => 'courier',
            // 10:07 — не совпадает ни с одним слотом FakeDeliveryClient (шаг 2 ч от 08:00)
            'deliverySlotId' => now()->addDay()->setTime(10, 7)->timestamp,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['deliverySlotId']);
    }

    // ==================== Самовывоз: слот обязателен всегда ====================

    /**
     * Модуль снимает слот только с курьерской доставки. Самовывоз завязан на
     * время выдачи в магазине, поэтому слот остаётся обязательным и при ON.
     */
    public function test_pickup_order_without_slot_is_rejected_when_module_enabled(): void
    {
        $this->enableCheckoutWithoutSlots();
        [$user, $store] = $this->pickupFixture();

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'shippingMethod' => 'pickup',
            'paymentType' => 'cash',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['deliverySlotId']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_pickup_order_with_slot_is_created_when_module_enabled(): void
    {
        $this->enableCheckoutWithoutSlots();
        [$user, $store] = $this->pickupFixture();
        $slotId = $this->getValidPickupSlotId($user, $store);

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'shippingMethod' => 'pickup',
            'paymentType' => 'cash',
            'deliverySlotId' => $slotId,
        ]);

        $response->assertCreated();

        $order = Order::findOrFail($response->json('orderId'));
        $this->assertSame($slotId, $order->delivery_slot_start->timestamp);
        $this->assertNotNull($order->delivery_slot_display);
        $this->assertSame(ShippingMethod::Pickup, $order->shipping_method);
    }

    public function test_pickup_order_with_unavailable_slot_is_rejected_when_module_enabled(): void
    {
        $this->enableCheckoutWithoutSlots();
        [$user, $store] = $this->pickupFixture();

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'shippingMethod' => 'pickup',
            'paymentType' => 'cash',
            // 03:00 — вне рабочих часов магазина (09:00–21:00)
            'deliverySlotId' => now()->addDay()->setTime(3, 0)->timestamp,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['deliverySlotId']);
    }

    /**
     * Защита от обхода FormRequest: action зовут и из других мест
     * (маркетплейсный CreateClientOrderAction, будущие интеграции).
     */
    public function test_pickup_order_without_slot_is_rejected_when_action_called_directly(): void
    {
        $this->enableCheckoutWithoutSlots();
        [$user, $store] = $this->pickupFixture();

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(\App\Actions\Order\CreateOrderAction::class)->execute(new \App\DTOs\Order\CreateOrderData(
            user: $user,
            storeId: $store->id,
            address: null,
            deliverySlotId: null,
            shippingMethod: ShippingMethod::Pickup,
            paymentType: 'cash',
        ));
    }

    /**
     * Слоты самовывоза выдаёт витрина сама (PickupSlotService), модулем не гейтятся.
     */
    public function test_pickup_slots_endpoint_still_returns_slots_when_module_enabled(): void
    {
        $this->enableCheckoutWithoutSlots();
        [$user, $store] = $this->pickupFixture();

        $response = $this->actingAs($user)
            ->getJson("/api/v1/stores/{$store->id}/pickup-slots?date=".now()->format('Y-m-d'));

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.slots'));
    }

    public function test_order_without_slot_is_rejected_when_store_is_closed_now(): void
    {
        $this->enableCheckoutWithoutSlots();
        [$user, $store, $address] = $this->courierFixture();
        // График на сегодня (четверг, iso=4): 09:00–11:00, сейчас 12:00 → закрыт
        StoreSchedule::create(['store_id' => $store->id, 'day_of_week' => 4, 'opens_at' => '09:00', 'closes_at' => '11:00']);

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'shippingMethod' => 'courier',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error', 'store_closed')
            ->assertJsonValidationErrors(['storeId']);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_without_slot_is_rejected_when_store_has_schedule_but_no_row_for_today(): void
    {
        $this->enableCheckoutWithoutSlots();
        [$user, $store, $address] = $this->courierFixture();
        // График заведён только на понедельник → в четверг магазин закрыт
        StoreSchedule::create(['store_id' => $store->id, 'day_of_week' => 1, 'opens_at' => '09:00', 'closes_at' => '21:00']);

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'shippingMethod' => 'courier',
        ]);

        $response->assertUnprocessable()->assertJsonPath('error', 'store_closed');
    }

    public function test_order_without_slot_is_created_when_store_is_open_now(): void
    {
        $this->enableCheckoutWithoutSlots();
        [$user, $store, $address] = $this->courierFixture();
        StoreSchedule::create(['store_id' => $store->id, 'day_of_week' => 4, 'opens_at' => '09:00', 'closes_at' => '21:00']);

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'shippingMethod' => 'courier',
        ]);

        $response->assertCreated();
    }

    public function test_order_without_slot_is_created_when_store_is_open_24_7_regardless_of_schedule(): void
    {
        $this->enableCheckoutWithoutSlots();
        [$user, $store, $address] = $this->courierFixture(['is_open_24_7' => true]);
        StoreSchedule::create(['store_id' => $store->id, 'day_of_week' => 4, 'opens_at' => '09:00', 'closes_at' => '11:00']);

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'shippingMethod' => 'courier',
        ]);

        $response->assertCreated();
    }

    public function test_order_with_slot_is_not_subject_to_store_open_guard(): void
    {
        $this->enableCheckoutWithoutSlots();
        [$user, $store, $address] = $this->courierFixture();
        // Магазин сейчас закрыт, но клиент выбрал слот на завтра — старое поведение
        StoreSchedule::create(['store_id' => $store->id, 'day_of_week' => 4, 'opens_at' => '09:00', 'closes_at' => '11:00']);

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'shippingMethod' => 'courier',
            'deliverySlotId' => $this->getValidDeliverySlotId(),
        ]);

        $response->assertCreated();
    }

    /**
     * Яндекс-зона: заявке нужен интервал (due), поэтому без слота бэк сам
     * подставляет ближайший доступный — клиенту прятать выбор можно всегда.
     * FakeDeliveryClient при now=12:00 отдаёт слоты с 14:00 (шаг 2 ч),
     * enrichWithYandexDelivery режет их по max_due_hours=4 → первый = 14:00.
     */
    public function test_yandex_zone_without_slot_auto_picks_first_available_slot(): void
    {
        $this->enableCheckoutWithoutSlots();
        [$user, $store, $address] = $this->courierFixture();
        $this->enableYandexDelivery();
        $this->mockPaymentGatewayHold();

        /** @var FakeDeliveryClient $client */
        $client = app(DeliveryClientInterface::class);
        $client->deliveryType = 'yandex';

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'shippingMethod' => 'courier',
            'paymentType' => 'card',
        ]);

        $response->assertCreated();

        $order = Order::findOrFail($response->json('orderId'));
        $this->assertSame(ShippingMethod::YandexDelivery, $order->shipping_method);
        $expectedStart = Carbon::parse('2026-07-02 14:00:00', config('app.timezone'));
        $this->assertTrue($expectedStart->equalTo($order->delivery_slot_start));
        $this->assertSame('14:00-16:00', $order->delivery_slot_display);
        $this->assertDatabaseHas('yandex_delivery_claims', [
            'order_id' => $order->id,
            'due_at' => $expectedStart->format('Y-m-d H:i:s'),
        ]);
    }

    public function test_yandex_zone_without_slot_is_rejected_when_no_slots_available(): void
    {
        $this->enableCheckoutWithoutSlots();
        // 21:30 — у FakeDeliveryClient на сегодня слотов уже нет (последний 20:00)
        Carbon::setTestNow(Carbon::parse('2026-07-02 21:30:00', config('app.timezone')));
        [$user, $store, $address] = $this->courierFixture(['is_open_24_7' => true]);
        $this->enableYandexDelivery();

        /** @var FakeDeliveryClient $client */
        $client = app(DeliveryClientInterface::class);
        $client->deliveryType = 'yandex';

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'shippingMethod' => 'courier',
            'paymentType' => 'card',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['deliverySlotId']);
        $this->assertDatabaseCount('orders', 0);
    }

    // ==================== Событие order.created ====================

    public function test_order_created_event_has_null_slot_for_order_without_slot(): void
    {
        $this->enableCheckoutWithoutSlots();
        [$user, $store, $address] = $this->courierFixture();

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'shippingMethod' => 'courier',
        ]);
        $response->assertCreated();

        $order = Order::with(['items', 'user', 'store'])->findOrFail($response->json('orderId'));
        $payload = app(OrderEventTransformer::class)->toEvent($order);

        $this->assertNull($payload['slot_start_date']);
        $this->assertNull($payload['slot_end_date']);
        $this->assertNull($payload['slot_time_slot']);
        $this->assertNull($payload['slot_date']);
    }

    // ==================== GET /api/v1/app-settings ====================

    public function test_app_settings_exposes_checkout_without_slots_flag_disabled_by_default(): void
    {
        $this->getJson('/api/v1/app-settings')
            ->assertOk()
            ->assertJsonPath('data.checkoutWithoutSlots', false);
    }

    public function test_app_settings_exposes_checkout_without_slots_flag_when_enabled(): void
    {
        $this->enableCheckoutWithoutSlots();

        $this->getJson('/api/v1/app-settings')
            ->assertOk()
            ->assertJsonPath('data.checkoutWithoutSlots', true);
    }

    // ==================== Скрытый маркетплейс: POST /client-orders ====================

    public function test_marketplace_client_order_without_delivery_slots_creates_sub_orders_when_module_enabled(): void
    {
        $this->enableCheckoutWithoutSlots(marketplace: true);
        $user = User::factory()->create();
        $address = Address::factory()->active()->for($user)->create();
        $store1 = $this->makeStore();
        $store2 = $this->makeStore();
        $this->addCartItem($user, $store1, 300, 2);
        $this->addCartItem($user, $store2, 400, 1);

        $result = app(CreateClientOrderAction::class)->execute(new CreateClientOrderData(
            user: $user,
            addressId: $address->id,
            paymentType: 'cash',
            deliverySlots: [],
        ));

        $this->assertCount(2, $result['subOrderIds']);
        $this->assertDatabaseCount('cart_items', 0);
        foreach ($result['subOrderIds'] as $id) {
            $this->assertNull(Order::findOrFail($id)->delivery_slot_start);
        }
    }

    public function test_marketplace_client_pickup_order_without_slots_is_rejected_when_module_enabled(): void
    {
        $this->enableCheckoutWithoutSlots(marketplace: true);
        $user = User::factory()->create();
        $address = Address::factory()->active()->for($user)->create();
        $store = $this->makeStore(['is_pickup_enabled' => true, 'pickup_payment_methods' => ['cash']]);
        $this->addCartItem($user, $store, 300, 2);

        $response = $this->actingAs($user)->postJson('/api/v1/client-orders', [
            'addressId' => $address->id,
            'shippingMethod' => 'pickup',
            'paymentType' => 'cash',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['deliverySlots']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_marketplace_client_order_request_accepts_missing_delivery_slots_when_module_enabled(): void
    {
        $this->enableCheckoutWithoutSlots(marketplace: true);
        $user = User::factory()->create();
        $address = Address::factory()->active()->for($user)->create();
        $store = $this->makeStore();
        $this->addCartItem($user, $store, 300, 2);

        $response = $this->actingAs($user)->postJson('/api/v1/client-orders', [
            'addressId' => $address->id,
            'shippingMethod' => 'courier',
            'paymentType' => 'cash',
        ]);

        // /client-orders исторически отвечает 200 (не 201)
        $response->assertOk()->assertJsonStructure(['clientOrderId', 'subOrderIds']);
        $this->assertCount(1, $response->json('subOrderIds'));
    }

    // ==================== Фикстуры ====================

    /**
     * Активирует Яндекс-доставку (флаг + интеграция), чтобы
     * DeliveryService::enrichWithYandexDelivery реально отрабатывал.
     */
    private function enableYandexDelivery(): void
    {
        config(['yandex-delivery.enabled' => true]);

        IntegrationConfig::create([
            'integration_type' => 'delivery',
            'driver' => 'yandex_go',
            'credentials' => [
                'oauth_token' => 'test_token',
                'emergency_phone' => '+79991234567',
                'callback_url' => 'https://test.com/webhook',
            ],
            'is_active' => true,
        ]);
    }

    /**
     * Подменяет платёжный шлюз: Яндекс требует card, а card-холд не должен бить по реальному YooKassa.
     */
    private function mockPaymentGatewayHold(): void
    {
        $this->mock(PaymentGateway::class, function ($mock) {
            $mock->shouldReceive('hold')->andReturn(new PaymentResult(
                success: true,
                paymentId: 'fake_pay_test',
                confirmationUrl: 'https://example.test/confirm',
                status: 'pending',
            ));
        });
    }

    /**
     * Включает модуль checkout_without_slots (и, опционально, скрытый маркетплейс).
     */
    private function enableCheckoutWithoutSlots(bool $marketplace = false): void
    {
        $modules = [
            'checkout_without_slots' => ['label' => 'Оформление заказа без слотов', 'enabled' => true],
        ];
        if ($marketplace) {
            $modules['marketplace_mode'] = ['type' => 'select', 'value' => 'marketplace'];
        }

        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            ['value' => json_encode($modules, JSON_UNESCAPED_UNICODE), 'type' => 'json'],
        );
        app(ModuleConfigService::class)->invalidateCache();
    }

    /**
     * Юзер + курьерский магазин (без графика, не 24/7) + адрес + корзина 2 × 300.
     *
     * @return array{0: User, 1: Store, 2: Address}
     */
    private function courierFixture(array $storeAttributes = []): array
    {
        $user = User::factory()->create();
        $address = Address::factory()->active()->for($user)->create();
        $store = $this->makeStore($storeAttributes);
        $this->addCartItem($user, $store, 300, 2);

        return [$user, $store, $address];
    }

    /**
     * Юзер + магазин с самовывозом (наличные) + корзина 2 × 300.
     *
     * @return array{0: User, 1: Store}
     */
    private function pickupFixture(): array
    {
        $user = User::factory()->create();
        $store = $this->makeStore([
            'is_pickup_enabled' => true,
            'pickup_payment_methods' => ['cash'],
            'slot_duration' => 60,
        ]);
        // Расписание на все дни недели 09:00–21:00 — чтобы слоты самовывоза
        // существовали и сегодня (четверг 12:00), и завтра.
        foreach (range(1, 7) as $day) {
            StoreSchedule::create([
                'store_id' => $store->id,
                'day_of_week' => $day,
                'opens_at' => '09:00',
                'closes_at' => '21:00',
            ]);
        }
        $this->addCartItem($user, $store, 300, 2);

        return [$user, $store];
    }

    /**
     * Первый доступный слот самовывоза магазина на сегодня (через публичный API).
     */
    private function getValidPickupSlotId(User $user, Store $store): int
    {
        $slots = $this->actingAs($user)
            ->getJson("/api/v1/stores/{$store->id}/pickup-slots?date=".now()->format('Y-m-d'))
            ->assertOk()
            ->json('data.slots');

        $this->assertNotEmpty($slots, 'Магазин должен отдавать слоты самовывоза');

        return (int) $slots[0]['id'];
    }

    private function makeStore(array $attributes = []): Store
    {
        return Store::factory()->create(array_merge([
            'min_order_amount' => 100,
            'delivery_price' => 0,
            'free_delivery_from' => null,
            'is_bonus_enabled' => false,
            'is_open_24_7' => false,
        ], $attributes));
    }

    private function addCartItem(User $user, Store $store, int $price, int $quantity): void
    {
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => $price,
            'quantity' => 10,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
        ]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => $quantity,
            'price' => $price,
        ]);
    }
}
