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
use App\Services\Delivery\Contracts\DeliveryClientInterface;
use App\Services\Delivery\FakeDeliveryClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TogglesDeliveryPriceRangesModule;
use Tests\TestCase;

/**
 * MID-280: доставка при оформлении по ступеням зоны. FakeDeliveryClient отдаёт
 * deliveryPrice=200, freeDeliveryFrom=2000 и ступени из $deliveryPriceRanges.
 */
final class CreateOrderDeliveryPriceRangesTest extends TestCase
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
        // Утро: слоты FakeDeliveryClient на сегодня есть, Яндекс берёт слот в пределах 4 часов
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

    private function courierOrder(float $sum, array $storeAttributes = []): Order
    {
        [$user, $store, $address] = $this->checkout($sum, $storeAttributes);

        $result = app(CreateOrderAction::class)->execute(new CreateOrderData(
            user: $user,
            storeId: $store->id,
            address: $address,
            deliverySlotId: $this->getValidDeliverySlotId(),
            shippingMethod: ShippingMethod::Courier,
            paymentType: 'cash',
        ));

        return Order::findOrFail($result['orderId']);
    }

    private function yandexOrder(float $sum): Order
    {
        [$user, $store, $address] = $this->checkout($sum);

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
        $this->mock(PaymentGateway::class, function ($mock) {
            $mock->shouldReceive('hold')->andReturn(new PaymentResult(
                success: true,
                paymentId: 'fake_pay_test',
                confirmationUrl: 'https://example.test/confirm',
                status: 'pending',
            ));
        });

        $result = app(CreateOrderAction::class)->execute(new CreateOrderData(
            user: $user,
            storeId: $store->id,
            address: $address,
            deliverySlotId: Carbon::parse('2026-07-02 10:00:00', config('app.timezone'))->timestamp,
            shippingMethod: ShippingMethod::Courier,
            paymentType: 'card',
        ));

        return Order::findOrFail($result['orderId']);
    }

    public function test_courier_order_takes_price_of_range(): void
    {
        $this->client()->deliveryPriceRanges = self::RANGES;

        foreach ([[700, 200], [1500, 100], [3000, 0]] as [$sum, $expected]) {
            $order = $this->courierOrder($sum);

            $this->assertSame((float) $expected, (float) $order->delivery_cost, "сумма {$sum}");
            $this->assertSame((float) ($sum + $expected), (float) $order->total, "сумма {$sum}");
        }
    }

    public function test_courier_zone_without_ranges_keeps_free_threshold(): void
    {
        // Ступеней нет: прежний порог зоны FakeDeliveryClient (от 2000 бесплатно)
        $this->assertSame(200.0, (float) $this->courierOrder(1999)->delivery_cost);
        $this->assertSame(0.0, (float) $this->courierOrder(2000)->delivery_cost);
    }

    /**
     * Фикс-раунд 1 (MID-280): зона без ступеней и без своего порога
     * (freeDeliveryFrom === null) — запасной порог берётся из stores.free_delivery_from,
     * ровно как рассчитывает CartCalculator::deliveryLadder().
     */
    public function test_courier_zone_without_own_threshold_falls_back_to_store_threshold(): void
    {
        $this->client()->freeDeliveryFrom = null;

        $order = $this->courierOrder(3000, ['free_delivery_from' => 3000]);

        $this->assertSame(0.0, (float) $order->delivery_cost);
    }

    public function test_yandex_order_is_free_from_free_range(): void
    {
        // Бесплатная ступень зоны 3000 важнее порога 2000 из freeDeliveryFrom
        $this->client()->deliveryPriceRanges = [['from_amount' => 3000, 'price' => 0]];

        $order = $this->yandexOrder(2500);

        $this->assertSame(ShippingMethod::YandexDelivery, $order->shipping_method);
        $this->assertSame(508.0, (float) $order->delivery_cost);
    }

    public function test_yandex_order_reaching_free_range_is_free(): void
    {
        $this->client()->deliveryPriceRanges = [['from_amount' => 3000, 'price' => 0]];

        $this->assertSame(0.0, (float) $this->yandexOrder(3000)->delivery_cost);
    }
}
