<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Actions\Order\CreateOrderAction;
use App\DTOs\Order\CreateOrderData;
use App\Enums\OrderStatus;
use App\Enums\ShippingMethod;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use App\Services\Delivery\Contracts\DeliveryClientInterface;
use App\Services\Delivery\FakeDeliveryClient;
use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use App\Services\SlotsApi\FakeSlotsApiClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TogglesDeliveryPriceRangesModule;
use Tests\TestCase;

/**
 * MID-280 Task 10: тумблер модуля delivery_price_ranges в project_modules.
 *
 * Два гейта, оба обязаны сработать при выключенном модуле: лесенка
 * (ZoneDeliveryLadder::make() игнорирует ступени, считает от цены+порога, как
 * до фичи) и выдача API (DeliveryPriceRanges::ranges()/nextStep() отдают
 * пустой контракт). Снимок заказа (delivery_price_snapshot) флаг не читает —
 * досчитывается по своему снимку независимо от текущего состояния тумблера.
 * Тест-страж красный, если снять любой из двух гейтов.
 */
final class DeliveryPriceRangesModuleTest extends TestCase
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
        // Утро: слоты FakeDeliveryClient на сегодня есть (как в остальных MID-280 тестах).
        Carbon::setTestNow(Carbon::parse('2026-07-02 08:00:00', config('app.timezone')));
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
    private function cartWithSum(float $sum, ?int $freeDeliveryFrom = 3000): array
    {
        $user = User::factory()->create();
        $address = Address::factory()->active()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
            'house_number' => '1',
        ]);

        $store = Store::factory()->create([
            'code' => 'shop-toggle',
            'delivery_price' => 200,
            'free_delivery_from' => $freeDeliveryFrom,
            'delivery_price_ranges' => self::RANGES,
            'min_order_amount' => 0,
            'latitude' => 55.75,
            'longitude' => 37.61,
        ]);

        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => $sum,
            'quantity' => 10,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
        ]);

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->for($store)->create([
            'quantity' => 1,
            'price' => $sum,
        ]);

        /** @var FakeSlotsApiClient $slots */
        $slots = $this->app->make(SlotsApiClientInterface::class);
        $slots->setShops([[
            'id' => 1,
            'code' => 'shop-toggle',
            'delivery_price' => 200,
            'min_order_price' => 0,
            'free_delivery_from' => $freeDeliveryFrom,
            'delivery_price_ranges' => self::RANGES,
        ]]);

        return [$user, $store, $address];
    }

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

        return Order::findOrFail($result['orderId']);
    }

    public function test_disabled_module_cart_uses_legacy_threshold_and_hides_api_fields(): void
    {
        $this->setDeliveryPriceRangesModule(false);
        // Со ступенями сумма 1500 попала бы в ступень 100 (от 1000). Флаг
        // выключен — считаем прежним правилом: цена зоны, пока не дошли до
        // порога бесплатной доставки 3000.
        [$user] = $this->cartWithSum(1500);

        $this->actingAs($user)->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('storeGroups.0.deliveryCost', 200)
            ->assertJsonPath('storeGroups.0.store.deliveryInfo.deliveryPriceRanges', [])
            ->assertJsonPath('storeGroups.0.nextDeliveryStep', null);
    }

    public function test_disabled_module_cart_is_free_only_from_threshold(): void
    {
        $this->setDeliveryPriceRangesModule(false);
        [$user] = $this->cartWithSum(3000);

        $this->actingAs($user)->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('storeGroups.0.deliveryCost', 0);
    }

    public function test_disabled_module_delivery_slots_and_calculate_hide_ranges(): void
    {
        $this->setDeliveryPriceRangesModule(false);
        [$user, $store, $address] = $this->cartWithSum(700);
        $this->client()->deliveryPriceRanges = self::RANGES;

        $date = now()->addDay()->format('Y-m-d');

        $this->actingAs($user)
            ->getJson("/api/v1/stores/{$store->id}/delivery-slots?addressId={$address->id}&date={$date}")
            ->assertOk()
            ->assertJsonPath('deliveryPriceRanges', []);

        $this->actingAs($user)
            ->getJson("/api/v1/delivery/calculate?shippingMethod=own_courier&shopId={$store->id}&addressId={$address->id}")
            ->assertOk()
            ->assertJsonPath('data.deliveryPriceRanges', []);
    }

    /**
     * Снимок нового заказа при выключенном модуле: ранее сконфигурированные
     * ступени зоны и её порог бесплатной доставки полностью проигнорированы —
     * ranges: [] (лесенка оформления строится из голой цены зоны, без
     * единственной «пороговой» ступени, так как порога тоже нет), а
     * checkout_price равен реально списанной доставке.
     */
    public function test_disabled_module_new_order_snapshot_is_empty_and_matches_delivery_cost(): void
    {
        $this->setDeliveryPriceRangesModule(false);
        [$user, $store, $address] = $this->cartWithSum(1500, null);
        $this->client()->deliveryPriceRanges = self::RANGES;
        $this->client()->freeDeliveryFrom = null;

        $order = $this->createCourierOrder($user, $store, $address);

        $this->assertSame(200.0, (float) $order->delivery_cost, 'ступень 100 от 1000 и бесплатный порог проигнорированы — фиксированная цена зоны');
        $this->assertEquals([
            'base_price' => 200,
            'ranges' => [],
            'checkout_price' => 200,
        ], $order->delivery_price_snapshot);
        $this->assertSame($order->delivery_price_snapshot['checkout_price'], (int) round($order->delivery_cost));
    }

    /**
     * Снимок сильнее флага: заказ создан со ступенями (снимок содержит их),
     * после выключения модуля пересчёт после сборки всё равно берёт ступень
     * из снимка, а не порог «как до фичи». Проверяет, что гейты 1 и 2 не
     * дотянулись до recalculateDeliveryCost() — снимок эти методы не читает.
     */
    public function test_disabled_module_still_recalculates_existing_order_by_its_snapshot(): void
    {
        $this->setDeliveryPriceRangesModule(false);

        $store = Store::factory()->create(['free_delivery_from' => null]);
        $order = Order::factory()->for(\App\Models\User::factory())->for($store)->create([
            'status' => OrderStatus::Assembling,
            'payment_type' => 'card',
            'subtotal' => 700,
            'discount' => 0,
            'delivery_cost' => 200,
            'delivery_price_snapshot' => [
                'base_price' => 200,
                'ranges' => self::RANGES,
                'checkout_price' => 200,
            ],
            'assembly_cost' => 0,
            'total' => 900,
        ]);
        $order->items()->create([
            'product_id' => null,
            'title' => 'Товар',
            'sku' => 'MID280-TOGGLE-SKU',
            'quantity' => 1,
            'price' => 700,
            'total' => 700,
            'eligible_for_bonus' => true,
            'status' => null,
        ]);

        app(OrderEventTransformer::class)->afterUpdate($order->fresh(), [
            'status' => 'assembling',
            'items' => [[
                'id' => 'ext-toggle-1',
                'source' => 'delivery',
                'product_name' => 'Товар',
                'item_status' => 'confirmed',
                'quantity' => 1,
                'unit_price' => 1500,
                'unit_price_effective' => 1500,
                'line_total' => 1500,
            ]],
        ]);

        $this->assertSame(100.0, (float) $order->fresh()->delivery_cost, 'снимок отдаёт ступень 100 от 1000, флаг выключен, но это не читается');
    }

    /**
     * При включённом модуле поведение задач 5 (корзина), 6 (оформление и
     * снимок) и 8 (API) не меняется тумблером.
     */
    public function test_enabled_module_keeps_ranges_in_cart_and_order_snapshot(): void
    {
        $this->setDeliveryPriceRangesModule(true);
        [$user, $store, $address] = $this->cartWithSum(1500);

        $this->actingAs($user)->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('storeGroups.0.deliveryCost', 100)
            ->assertJsonPath('storeGroups.0.store.deliveryInfo.deliveryPriceRanges', [
                ['fromAmount' => 1000, 'price' => 100],
                ['fromAmount' => 3000, 'price' => 0],
            ])
            ->assertJsonPath('storeGroups.0.nextDeliveryStep.fromAmount', 3000);

        $this->client()->deliveryPriceRanges = self::RANGES;
        $order = $this->createCourierOrder($user, $store, $address);

        $this->assertSame(100.0, (float) $order->delivery_cost);
        $this->assertEquals([
            'base_price' => 200,
            'ranges' => self::RANGES,
            'checkout_price' => 100,
        ], $order->delivery_price_snapshot);
    }
}
