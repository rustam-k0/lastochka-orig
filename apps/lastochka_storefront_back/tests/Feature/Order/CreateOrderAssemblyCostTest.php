<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Actions\Order\CreateOrderAction;
use App\Contracts\PaymentGateway;
use App\DTOs\Order\CreateOrderData;
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
use App\Models\User;
use App\Services\Delivery\Contracts\DeliveryClientInterface;
use App\Services\Delivery\FakeDeliveryClient;
use App\Services\Pickup\PickupSlotService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Врезка стоимости сборки (модуль assembly_cost) в сумму заказа.
 *
 * Мы идём самовывозом (ShippingMethod::Pickup) — тот же путь, что в
 * CreateOrderPickupOnlyRestrictionTest::test_pickup_order_..._succeeds — чтобы
 * deliveryCost детерминированно был 0 (без Yandex/slots-api) и total можно было
 * проверить точно: total = subtotal - discount + deliveryCost + assemblyCost.
 */
final class CreateOrderAssemblyCostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Фиксируем время на безопасное утро — самовывоз (24/7 магазин) читает
        // слоты от now() и без заморозки флакует поздно вечером (см.
        // PickupSlotService::getSlots — "пропускаем прошедшие слоты").
        Carbon::setTestNow(Carbon::parse('2026-07-02 08:00:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_order_total_includes_assembly_cost_when_enabled(): void
    {
        $this->enableAssemblyCostModule();
        [$user, $store] = $this->pickupCheckoutFixture(assemblyPrice: 100, price: 1000);

        $order = $this->createPickupOrder($user, $store);

        // subtotal=1000, discount=0, deliveryCost=0 (самовывоз), assemblyCost=100
        $this->assertSame(100, (int) $order->assembly_cost);
        $this->assertSame(1100.0, (float) $order->total);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'assembly_cost' => 100]);
    }

    public function test_order_assembly_cost_zero_when_flag_disabled(): void
    {
        // Модуль НЕ включаем: assembly_price=100 задан, но в total НЕ попадает.
        [$user, $store] = $this->pickupCheckoutFixture(assemblyPrice: 100, price: 1000);

        $order = $this->createPickupOrder($user, $store);

        $this->assertSame(0, (int) $order->assembly_cost);
        $this->assertSame(1000.0, (float) $order->total);
    }

    public function test_order_assembly_cost_zero_when_price_null(): void
    {
        $this->enableAssemblyCostModule();
        [$user, $store] = $this->pickupCheckoutFixture(assemblyPrice: null, price: 1000);

        $order = $this->createPickupOrder($user, $store);

        $this->assertSame(0, (int) $order->assembly_cost);
        $this->assertSame(1000.0, (float) $order->total);
    }

    /**
     * Курьерская доставка: зонная стоимость сборки из slots-api (assemblyPrice)
     * имеет приоритет над магазинной $store->assembly_price.
     */
    public function test_courier_order_uses_zone_assembly_price_when_present(): void
    {
        $this->enableAssemblyCostModule();
        [$user, $store, $address] = $this->courierCheckoutFixture(storeAssemblyPrice: 100);
        $this->setZoneAssemblyPrice(300);

        $order = $this->createCourierOrder($user, $store, $address);

        // subtotal=600, discount=0, deliveryCost=200 (600<freeFrom=2000), assemblyCost=300 (зонная)
        $this->assertSame(300, (int) $order->assembly_cost);
        $this->assertSame(1100.0, (float) $order->total);
    }

    /**
     * Курьерская доставка: зона без своей цены (assemblyPrice=null) →
     * fallback на магазинную $store->assembly_price (текущее поведение).
     */
    public function test_courier_order_falls_back_to_store_assembly_price_when_zone_null(): void
    {
        $this->enableAssemblyCostModule();
        [$user, $store, $address] = $this->courierCheckoutFixture(storeAssemblyPrice: 100);
        $this->setZoneAssemblyPrice(null);

        $order = $this->createCourierOrder($user, $store, $address);

        // assemblyCost=100 (магазинная), total = 600 + 200 + 100
        $this->assertSame(100, (int) $order->assembly_cost);
        $this->assertSame(900.0, (float) $order->total);
    }

    /**
     * Курьерская доставка: выключенный модуль → assemblyCost=0, даже если зона
     * прислала свою цену.
     */
    public function test_courier_order_assembly_cost_zero_when_flag_disabled(): void
    {
        // Модуль НЕ включаем.
        [$user, $store, $address] = $this->courierCheckoutFixture(storeAssemblyPrice: 100);
        $this->setZoneAssemblyPrice(300);

        $order = $this->createCourierOrder($user, $store, $address);

        $this->assertSame(0, (int) $order->assembly_cost);
        $this->assertSame(800.0, (float) $order->total);
    }

    /**
     * Семантическая граница: зона с assembly_price=0 даёт assemblyCost=0, а НЕ
     * fallback на магазинную. `??` срабатывает только на null, не на 0 — 0 это
     * валидная зонная цена «сборка бесплатна», её нельзя перезатирать магазинной.
     */
    public function test_courier_order_zone_assembly_price_zero_is_not_store_fallback(): void
    {
        $this->enableAssemblyCostModule();
        [$user, $store, $address] = $this->courierCheckoutFixture(storeAssemblyPrice: 100);
        $this->setZoneAssemblyPrice(0);

        $order = $this->createCourierOrder($user, $store, $address);

        // zone=0 → assemblyCost=0 (не магазинная 100), total = 600 + 200 + 0
        $this->assertSame(0, (int) $order->assembly_cost);
        $this->assertSame(800.0, (float) $order->total);
    }

    /**
     * Яндекс-доставка: зонная assembly_price доезжает до заказа через
     * DeliveryService::enrichWithYandexDelivery (метод пересобирает
     * DeliveryInfoData — без проброса assemblyPrice поле терялось бы). Заказ
     * реально идёт по yandex-пути (shipping_method=YandexDelivery).
     */
    public function test_yandex_order_uses_zone_assembly_price_via_enrichment(): void
    {
        $this->enableAssemblyCostModule();
        [$user, $store, $address] = $this->courierCheckoutFixture(storeAssemblyPrice: 100);
        $this->enableYandexDelivery();

        /** @var FakeDeliveryClient $client */
        $client = app(DeliveryClientInterface::class);
        $client->deliveryType = 'yandex';
        $client->assemblyPrice = 300;

        // Yandex требует card-оплату → CreateOrderAction дёргает createHold.
        // Мокаем платёжный шлюз, чтобы не бить по реальному YooKassa.
        $this->mockPaymentGatewayHold();

        // Слот 10:00 того же дня (08:00 заморожено в setUp) — в пределах
        // max_due_hours=4, иначе yandex-проверка отклонит слот.
        $slotId = Carbon::parse('2026-07-02 10:00:00', config('app.timezone'))->timestamp;

        $result = app(CreateOrderAction::class)->execute(new CreateOrderData(
            user: $user,
            storeId: $store->id,
            address: $address,
            deliverySlotId: $slotId,
            shippingMethod: ShippingMethod::Courier,
            paymentType: 'card',
        ));

        $order = Order::findOrFail($result['orderId']);

        // Заказ действительно ушёл по yandex-пути и зонная цена сборки доехала.
        $this->assertSame(ShippingMethod::YandexDelivery->value, $order->shipping_method->value);
        $this->assertSame(300, (int) $order->assembly_cost);
    }

    /**
     * Устанавливает зонную стоимость сборки, возвращаемую FakeDeliveryClient
     * (эмуляция ключа assembly_price из ответа slots-api /slots/available).
     */
    private function setZoneAssemblyPrice(?int $assemblyPrice): void
    {
        /** @var FakeDeliveryClient $client */
        $client = app(DeliveryClientInterface::class);
        $client->assemblyPrice = $assemblyPrice;
    }

    /**
     * Активирует зону Яндекс-доставки (интеграция delivery + флаг), чтобы
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
     * Подменяет платёжный шлюз, чтобы card-холд не бил по реальному YooKassa.
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
     * Оформляет курьерский заказ и возвращает свежую модель Order.
     */
    private function createCourierOrder(User $user, Store $store, Address $address): Order
    {
        $result = app(CreateOrderAction::class)->execute(new CreateOrderData(
            user: $user,
            storeId: $store->id,
            address: $address,
            deliverySlotId: $this->getValidDeliverySlotId(),
            shippingMethod: ShippingMethod::Courier,
            paymentType: 'cash',
        ));

        $this->assertNotNull($result['orderId']);

        return Order::findOrFail($result['orderId']);
    }

    /**
     * Юзер + курьерский магазин + адрес + корзина (2 × 300 = 600).
     * FakeDeliveryClient отдаёт deliveryPrice=200, freeDeliveryFrom=2000.
     *
     * @return array{0: User, 1: Store, 2: Address}
     */
    private function courierCheckoutFixture(?int $storeAssemblyPrice): array
    {
        $user = User::factory()->create();
        $address = Address::factory()->active()->for($user)->create();

        $store = Store::factory()->create([
            'min_order_amount' => 100,
            'delivery_price' => 0,
            'free_delivery_from' => null,
            'is_bonus_enabled' => false,
            'assembly_price' => $storeAssemblyPrice,
        ]);

        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'sku' => 'ASSEMBLY-COURIER-SKU',
            'price' => 300,
            'quantity' => 10,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
        ]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 2,
            'price' => 300,
        ]);

        return [$user, $store, $address];
    }

    private function enableAssemblyCostModule(): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => 'project_modules'],
            [
                'value' => json_encode(['assembly_cost' => ['label' => 'Стоимость сборки', 'enabled' => true]]),
                'type' => 'json',
            ]
        );

        Cache::flush();
    }

    /**
     * Оформляет самовывозный заказ и возвращает свежую модель Order.
     */
    private function createPickupOrder(User $user, Store $store): Order
    {
        $slots = app(PickupSlotService::class)->getSlots($store, now()->format('Y-m-d'), null);
        $this->assertNotEmpty($slots, 'у круглосуточного магазина должен быть хотя бы один слот самовывоза сегодня');

        $result = app(CreateOrderAction::class)->execute(new CreateOrderData(
            user: $user,
            storeId: $store->id,
            address: null,
            deliverySlotId: $slots[0]['id'],
            shippingMethod: ShippingMethod::Pickup,
            paymentType: 'cash',
        ));

        $this->assertNotNull($result['orderId']);

        return Order::findOrFail($result['orderId']);
    }

    /**
     * Юзер + самовывозный магазин с assembly_price + корзина со штучным товаром.
     * Паттерн фикстуры совпадает с CreateOrderPickupOnlyRestrictionTest.
     *
     * @return array{0: User, 1: Store}
     */
    private function pickupCheckoutFixture(?int $assemblyPrice, int $price): array
    {
        $user = User::factory()->create();
        $store = Store::factory()->create([
            'is_pickup_enabled' => true,
            'is_open_24_7' => true,
            'slot_duration' => 60,
            'min_pickup_order_amount' => 0,
            'min_order_amount' => 0,
            'pickup_payment_methods' => ['cash', 'card'],
            'is_bonus_enabled' => false,
            'delivery_price' => 0,
            'free_delivery_from' => null,
            'assembly_price' => $assemblyPrice,
        ]);

        $product = Product::factory()->for($store)->create([
            'sku' => 'ASSEMBLY-SKU',
            'price' => $price,
            'quantity' => 10,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
        ]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => $price,
        ]);

        return [$user, $store];
    }
}
