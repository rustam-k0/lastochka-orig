<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

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
use App\Models\User;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use App\Services\Delivery\Contracts\DeliveryClientInterface;
use App\Services\Delivery\FakeDeliveryClient;
use App\Services\Pickup\PickupSlotService;
use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use App\Services\SlotsApi\FakeSlotsApiClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TogglesDeliveryPriceRangesModule;
use Tests\TestCase;

/**
 * MID-280: снимок условий доставки в каждом заказе и в событии order.created.
 * FakeDeliveryClient: deliveryPrice=200, freeDeliveryFrom=2000.
 */
final class OrderDeliveryPriceSnapshotTest extends TestCase
{
    use RefreshDatabase;
    use TogglesDeliveryPriceRangesModule;

    private const RANGES = [
        ['from_amount' => 1000, 'price' => 100],
        ['from_amount' => 3000, 'price' => 0],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-02 08:00:00', config('app.timezone')));
        // Тесты написаны до тумблера (Task 10): модуль включён, поведение прежнее.
        $this->setDeliveryPriceRangesModule(true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function client(): FakeDeliveryClient
    {
        /** @var FakeDeliveryClient $client */
        $client = app(DeliveryClientInterface::class);

        return $client;
    }

    /**
     * @return array{0: User, 1: Store, 2: Address}
     */
    private function checkout(float $sum, array $storeAttributes = []): array
    {
        $user = User::factory()->create();
        $address = Address::factory()->active()->for($user)->create(['house_number' => '1']);

        $store = Store::factory()->create(array_merge([
            'min_order_amount' => 0,
            'delivery_price' => 0,
            'free_delivery_from' => null,
            'is_bonus_enabled' => false,
            'latitude' => 55.75,
            'longitude' => 37.61,
        ], $storeAttributes));

        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => $sum,
            'quantity' => 10,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
        ]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => $sum,
        ]);

        return [$user, $store, $address];
    }

    private function create(CreateOrderData $data): Order
    {
        $result = app(CreateOrderAction::class)->execute($data);

        return Order::findOrFail($result['orderId']);
    }

    private function courierOrder(float $sum): Order
    {
        [$user, $store, $address] = $this->checkout($sum);

        return $this->create(new CreateOrderData(
            user: $user,
            storeId: $store->id,
            address: $address,
            deliverySlotId: $this->getValidDeliverySlotId(),
            shippingMethod: ShippingMethod::Courier,
            paymentType: 'cash',
        ));
    }

    public function test_courier_order_snapshots_zone_ranges(): void
    {
        $this->client()->deliveryPriceRanges = self::RANGES;

        $order = $this->courierOrder(1500);

        $this->assertEquals([
            'base_price' => 200,
            'ranges' => self::RANGES,
            'checkout_price' => 100,
        ], $order->delivery_price_snapshot);
        // Кросс-проверка (MID-280 фикс-раунд 1): checkout_price снимка обязан
        // совпадать с колонкой delivery_cost этого же заказа, а не только с
        // ожидаемым числом теста.
        $this->assertSame($order->delivery_price_snapshot['checkout_price'], (int) round($order->delivery_cost));
    }

    public function test_courier_zone_without_ranges_snapshots_free_threshold(): void
    {
        $order = $this->courierOrder(700);

        $this->assertEquals([
            'base_price' => 200,
            'ranges' => [['from_amount' => 2000, 'price' => 0]],
            'checkout_price' => 200,
        ], $order->delivery_price_snapshot);
        $this->assertSame($order->delivery_price_snapshot['checkout_price'], (int) round($order->delivery_cost));
    }

    public function test_yandex_order_snapshots_yandex_price_and_free_range_only(): void
    {
        [$user, $store, $address] = $this->checkout(2500);
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
        $this->client()->deliveryType = 'yandex';
        $this->client()->deliveryPriceRanges = [['from_amount' => 3000, 'price' => 0]];
        $this->mock(PaymentGateway::class, function ($mock) {
            $mock->shouldReceive('hold')->andReturn(new PaymentResult(
                success: true,
                paymentId: 'fake_pay_test',
                confirmationUrl: 'https://example.test/confirm',
                status: 'pending',
            ));
        });

        $order = $this->create(new CreateOrderData(
            user: $user,
            storeId: $store->id,
            address: $address,
            deliverySlotId: Carbon::parse('2026-07-02 10:00:00', config('app.timezone'))->timestamp,
            shippingMethod: ShippingMethod::Courier,
            paymentType: 'card',
        ));

        // FakeYandexGoClient: 507.52 → priceRounded() = 508
        $this->assertEquals([
            'base_price' => 508,
            'ranges' => [['from_amount' => 3000, 'price' => 0]],
            'checkout_price' => 508,
        ], $order->delivery_price_snapshot);
        $this->assertSame($order->delivery_price_snapshot['checkout_price'], (int) round($order->delivery_cost));
    }

    public function test_pickup_order_snapshots_fixed_zero_price(): void
    {
        [$user, $store] = $this->checkout(700, [
            'is_pickup_enabled' => true,
            'is_open_24_7' => true,
            'slot_duration' => 60,
            'min_pickup_order_amount' => 0,
            'pickup_payment_methods' => ['cash', 'card'],
        ]);
        $slots = app(PickupSlotService::class)->getSlots($store, now()->format('Y-m-d'), null);

        $order = $this->create(new CreateOrderData(
            user: $user,
            storeId: $store->id,
            address: null,
            deliverySlotId: $slots[0]['id'],
            shippingMethod: ShippingMethod::Pickup,
            paymentType: 'cash',
        ));

        $this->assertEquals(['base_price' => 0, 'ranges' => [], 'checkout_price' => 0], $order->delivery_price_snapshot);
        $this->assertSame($order->delivery_price_snapshot['checkout_price'], (int) round($order->delivery_cost));
    }

    public function test_cdek_order_snapshots_fixed_tariff(): void
    {
        /** @var FakeSlotsApiClient $slots */
        $slots = app(SlotsApiClientInterface::class);
        $slots->setCdekTariffs([
            ['code' => '136', 'name' => 'Склад-Склад', 'price' => 350, 'currency' => 'RUB'],
        ]);

        [$user, $store] = $this->checkout(500);
        $address = Address::factory()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
            'is_active' => true,
            'additional' => [
                'kind' => 'pickup',
                'provider' => 'cdek',
                'external_id' => 'CDEK-MSK-001',
            ],
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'shippingMethod' => 'cdek',
            'shippingTariffCode' => '136',
        ]);
        $response->assertCreated();

        $order = Order::findOrFail($response->json('orderId'));

        $this->assertEquals(['base_price' => 350, 'ranges' => [], 'checkout_price' => 350], $order->delivery_price_snapshot);
        $this->assertSame($order->delivery_price_snapshot['checkout_price'], (int) round($order->delivery_cost));
    }

    public function test_russian_post_order_snapshots_fixed_tariff(): void
    {
        /** @var FakeSlotsApiClient $slots */
        $slots = app(SlotsApiClientInterface::class);
        $slots->setRpTariffs([
            ['code' => 'online-parcel', 'name' => 'Посылка онлайн', 'price' => 280, 'currency' => 'RUB'],
        ]);

        [$user, $store] = $this->checkout(500);
        $address = Address::factory()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
            'is_active' => true,
            'additional' => [
                'kind' => 'pickup',
                'provider' => 'russian_post',
                'external_id' => 'RP-MSK-101200',
            ],
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'shippingMethod' => 'russian_post',
            'shippingTariffCode' => 'online-parcel',
        ]);
        $response->assertCreated();

        $order = Order::findOrFail($response->json('orderId'));

        $this->assertEquals(['base_price' => 280, 'ranges' => [], 'checkout_price' => 280], $order->delivery_price_snapshot);
        $this->assertSame($order->delivery_price_snapshot['checkout_price'], (int) round($order->delivery_cost));
    }

    /**
     * MID-280 фикс-раунд 1 (Important): корзина с товарами двух магазинов —
     * каждый заказ получает свой снимок с условиями СВОЕГО магазина, а не
     * зоны/суммы соседнего заказа. Второй заказ оформляется уже при других
     * условиях Fake-клиента (без ступеней, другой порог) — если бы снимок
     * тянулся из общего/устаревшего состояния, а не считался заново на
     * своих $deliveryInfo/$deliveryCost, тест поймал бы подмену.
     */
    public function test_multi_store_cart_snapshots_each_order_with_its_own_store_conditions(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->active()->for($user)->create(['house_number' => '1']);
        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);

        $storeWithRanges = Store::factory()->create([
            'min_order_amount' => 0,
            'delivery_price' => 0,
            'free_delivery_from' => null,
            'is_bonus_enabled' => false,
            'latitude' => 55.75,
            'longitude' => 37.61,
        ]);
        $categoryA = Category::factory()->forStore($storeWithRanges)->create();
        $productA = Product::factory()->for($storeWithRanges)->inCategory($categoryA)->create([
            'price' => 1500,
            'quantity' => 10,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
        ]);
        CartItem::factory()->for($cart)->for($productA)->create([
            'store_id' => $storeWithRanges->id,
            'quantity' => 1,
            'price' => 1500,
        ]);

        $storeWithThreshold = Store::factory()->create([
            'min_order_amount' => 0,
            'delivery_price' => 0,
            'free_delivery_from' => null,
            'is_bonus_enabled' => false,
            'latitude' => 55.76,
            'longitude' => 37.62,
        ]);
        $categoryB = Category::factory()->forStore($storeWithThreshold)->create();
        $productB = Product::factory()->for($storeWithThreshold)->inCategory($categoryB)->create([
            'price' => 700,
            'quantity' => 10,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
        ]);
        CartItem::factory()->for($cart)->for($productB)->create([
            'store_id' => $storeWithThreshold->id,
            'quantity' => 1,
            'price' => 700,
        ]);

        // Магазин 1: ступени зоны — оформляем первым.
        $this->client()->deliveryPriceRanges = self::RANGES;
        $orderWithRanges = $this->create(new CreateOrderData(
            user: $user,
            storeId: $storeWithRanges->id,
            address: $address,
            deliverySlotId: $this->getValidDeliverySlotId(),
            shippingMethod: ShippingMethod::Courier,
            paymentType: 'cash',
        ));

        // Магазин 2: условия зоны меняются ПОСЛЕ первого оформления (без ступеней,
        // другой порог) — второй заказ обязан снять именно их, не унаследовать
        // от первого.
        $this->client()->deliveryPriceRanges = [];
        $this->client()->freeDeliveryFrom = 900;
        $orderWithThreshold = $this->create(new CreateOrderData(
            user: $user,
            storeId: $storeWithThreshold->id,
            address: $address,
            deliverySlotId: $this->getValidDeliverySlotId(),
            shippingMethod: ShippingMethod::Courier,
            paymentType: 'cash',
        ));

        $this->assertEquals([
            'base_price' => 200,
            'ranges' => self::RANGES,
            'checkout_price' => 100,
        ], $orderWithRanges->delivery_price_snapshot);
        $this->assertSame($orderWithRanges->delivery_price_snapshot['checkout_price'], (int) round($orderWithRanges->delivery_cost));

        $this->assertEquals([
            'base_price' => 200,
            'ranges' => [['from_amount' => 900, 'price' => 0]],
            'checkout_price' => 200,
        ], $orderWithThreshold->delivery_price_snapshot);
        $this->assertSame($orderWithThreshold->delivery_price_snapshot['checkout_price'], (int) round($orderWithThreshold->delivery_cost));

        // Снимки двух заказов из одной корзины не совпадают и не перетёрлись.
        $this->assertNotEquals($orderWithRanges->delivery_price_snapshot, $orderWithThreshold->delivery_price_snapshot);
    }

    public function test_created_events_carry_snapshot(): void
    {
        $this->client()->deliveryPriceRanges = self::RANGES;
        $order = $this->courierOrder(1500)->fresh();
        $transformer = app(OrderEventTransformer::class);
        $expected = ['base_price' => 200, 'ranges' => self::RANGES, 'checkout_price' => 100];

        $this->assertEquals($expected, $transformer->toEvent($order)['delivery_price_snapshot']);
        $this->assertEquals($expected, $transformer->toCustomEvent($order, 'order_created', [])['delivery_price_snapshot']);
    }

    public function test_order_without_snapshot_has_no_key_in_event(): void
    {
        $order = $this->courierOrder(1500);
        $order->forceFill(['delivery_price_snapshot' => null])->saveQuietly();

        $this->assertArrayNotHasKey('delivery_price_snapshot', app(OrderEventTransformer::class)->toEvent($order->fresh()));
    }

    public function test_yandex_updated_event_does_not_carry_snapshot(): void
    {
        // Снимок едет только в событии создания: обновление статуса Яндекса не должно
        // затирать снимок, который курьерка могла пересобрать (смена магазина заказа)
        $order = $this->courierOrder(1500)->fresh();

        $payload = app(OrderEventTransformer::class)->toCustomEvent($order, 'order_yandex_updated', []);

        $this->assertArrayNotHasKey('delivery_price_snapshot', $payload);
    }
}
