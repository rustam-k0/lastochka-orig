<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Actions\Order\CreateClientOrderAction;
use App\DTOs\Order\CreateClientOrderData;
use App\Enums\ShippingMethod;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\Pickup\PickupSlotService;
use App\Services\Settings\ModuleConfigService;
use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use App\Services\SlotsApi\FakeSlotsApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Тесты ветки cdek/russian_post в CreateClientOrderAction (marketplace).
 *
 * Ветка cdek/rp активируется через CreateClientOrderData::shippingMethod
 * и пропускает deliverySlots — группы создаются из уникальных store_id корзины.
 *
 * Защита backward compat: `test_courier_path_still_creates_orders_via_delivery_slots`
 * проверяет что старый сценарий по слотам не сломан.
 */
final class CreateClientOrderCdekRpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->enableMarketplaceMode();
        $this->enableDeliveryModules(cdek: true, rp: true);
    }

    public function test_creates_cdek_sub_orders_grouped_by_store_id(): void
    {
        [$user, , $store1, $store2] = $this->seedCartWithTwoStores();
        $pickup = $this->makePickupAddress($user, 'cdek');

        $this->fakeSlotsApi()->setShopIntegrationConfigs([
            ['integration_type' => 'delivery_cdek', 'is_active' => true],
        ]);
        $this->fakeSlotsApi()->setCdekTariffs([
            ['code' => '136', 'name' => 'Склад-Склад', 'price' => 350, 'currency' => 'RUB'],
        ]);

        /** @var CreateClientOrderAction $action */
        $action = app(CreateClientOrderAction::class);

        $result = $action->execute(new CreateClientOrderData(
            user: $user,
            addressId: $pickup->id,
            paymentType: 'cash',
            deliverySlots: [],
            shippingMethod: ShippingMethod::Cdek,
            shippingTariffCodes: [
                $store1->id => '136',
                $store2->id => '136',
            ],
        ));

        $this->assertCount(2, $result['subOrderIds']);

        $orders = Order::query()
            ->whereIn('id', $result['subOrderIds'])
            ->orderBy('store_id')
            ->get();

        $this->assertSame($result['clientOrderId'], (string) $orders[0]->client_order_id);
        $this->assertSame($result['clientOrderId'], (string) $orders[1]->client_order_id);

        foreach ($orders as $order) {
            $this->assertSame(ShippingMethod::Cdek, $order->shipping_method);
            $this->assertSame('136', (string) $order->shipping_tariff_code);
            $this->assertNull($order->delivery_slot_id);
            $this->assertSame(350, (int) $order->delivery_cost, 'delivery_cost = цена тарифа из slots-api');
            $this->assertSame(
                (int) $order->subtotal - (int) $order->discount - (int) $order->bonus_spent + 350,
                (int) $order->total,
                'total включает стоимость доставки',
            );
        }

        // totalAmount client-order'а агрегирует доставку обоих sub-orders.
        // Ресурс отдаёт его как round((float) …, 2), поэтому сравниваем
        // во float, а не приведением к int.
        $this->assertIsFloat($result['totalAmount']);
        $this->assertEqualsWithDelta(
            round((float) $orders->sum('total'), 2),
            $result['totalAmount'],
            0.01,
        );
    }

    public function test_cdek_tariff_unavailable_throws_422_and_does_not_create_orders(): void
    {
        [$user, , $store1, $store2] = $this->seedCartWithTwoStores();
        $pickup = $this->makePickupAddress($user, 'cdek');

        $this->fakeSlotsApi()->setShopIntegrationConfigs([
            ['integration_type' => 'delivery_cdek', 'is_active' => true],
        ]);
        // Тариф '136' в списке отсутствует — провайдер вернул другие коды.
        $this->fakeSlotsApi()->setCdekTariffs([
            ['code' => '139', 'name' => 'Дверь-Дверь', 'price' => 500],
        ]);

        $this->expectException(ValidationException::class);

        try {
            app(CreateClientOrderAction::class)->execute(new CreateClientOrderData(
                user: $user,
                addressId: $pickup->id,
                paymentType: 'cash',
                deliverySlots: [],
                shippingMethod: ShippingMethod::Cdek,
                shippingTariffCodes: [
                    $store1->id => '136',
                    $store2->id => '136',
                ],
            ));
        } finally {
            $this->assertSame(0, Order::query()->count(), 'ни один Order не должен быть создан');
        }
    }

    public function test_creates_russian_post_sub_orders_with_scalar_tariff_code(): void
    {
        [$user, , $store1, $store2] = $this->seedCartWithTwoStores();
        $pickup = $this->makePickupAddress($user, 'russian_post');

        $this->fakeSlotsApi()->setShopIntegrationConfigs([
            ['integration_type' => 'delivery_russian_post', 'is_active' => true],
        ]);
        $this->fakeSlotsApi()->setRpTariffs([
            ['code' => 'ONLINE_PARCEL_ORDINARY', 'name' => 'Посылка онлайн обычная', 'price' => 280],
        ]);

        $result = app(CreateClientOrderAction::class)->execute(new CreateClientOrderData(
            user: $user,
            addressId: $pickup->id,
            paymentType: 'cash',
            deliverySlots: [],
            shippingMethod: ShippingMethod::RussianPost,
            shippingTariffCodes: 'ONLINE_PARCEL_ORDINARY',
        ));

        $this->assertCount(2, $result['subOrderIds']);

        $orders = Order::query()
            ->whereIn('id', $result['subOrderIds'])
            ->get();

        foreach ($orders as $order) {
            $this->assertSame(ShippingMethod::RussianPost, $order->shipping_method);
            $this->assertSame('ONLINE_PARCEL_ORDINARY', (string) $order->shipping_tariff_code);
            $this->assertSame(280, (int) $order->delivery_cost);
            $this->assertSame(
                (int) $order->subtotal - (int) $order->discount - (int) $order->bonus_spent + 280,
                (int) $order->total,
            );
        }

        $this->assertCount(2, $orders->pluck('store_id')->unique());
        $this->assertContains($store1->id, $orders->pluck('store_id')->all());
        $this->assertContains($store2->id, $orders->pluck('store_id')->all());
    }

    public function test_rejects_cdek_when_store_integration_inactive(): void
    {
        [$user] = $this->seedCartWithTwoStores();
        $pickup = $this->makePickupAddress($user, 'cdek');

        // FakeSlotsApiClient отвечает is_active=false для всех магазинов корзины.
        $this->fakeSlotsApi()->setShopIntegrationConfigs([
            ['integration_type' => 'delivery_cdek', 'is_active' => false],
        ]);

        $this->expectException(ValidationException::class);

        try {
            app(CreateClientOrderAction::class)->execute(new CreateClientOrderData(
                user: $user,
                addressId: $pickup->id,
                paymentType: 'cash',
                deliverySlots: [],
                shippingMethod: ShippingMethod::Cdek,
                shippingTariffCodes: '136',
            ));
        } finally {
            // Доп.проверка: ни одного Order не создалось.
            $this->assertSame(0, Order::query()->count());
        }
    }

    public function test_rejects_cdek_when_module_disabled(): void
    {
        $this->enableDeliveryModules(cdek: false, rp: true);

        [$user] = $this->seedCartWithTwoStores();
        $pickup = $this->makePickupAddress($user, 'cdek');

        $this->fakeSlotsApi()->setShopIntegrationConfigs([
            ['integration_type' => 'delivery_cdek', 'is_active' => true],
        ]);

        $this->expectException(ValidationException::class);

        try {
            app(CreateClientOrderAction::class)->execute(new CreateClientOrderData(
                user: $user,
                addressId: $pickup->id,
                paymentType: 'cash',
                deliverySlots: [],
                shippingMethod: ShippingMethod::Cdek,
                shippingTariffCodes: '136',
            ));
        } finally {
            $this->assertSame(0, Order::query()->count());
        }
    }

    public function test_explicit_pickup_method_sets_shipping_method_pickup_on_sub_orders(): void
    {
        [$user, $address, $store1, $store2] = $this->seedCartWithTwoStores();

        // Берём slot ровно как его сгенерит PickupSlotService для is_open_24_7=true.
        $tomorrow = now()->addDay()->format('Y-m-d');
        $slots1 = app(PickupSlotService::class)->getSlots($store1, $tomorrow);
        $slots2 = app(PickupSlotService::class)->getSlots($store2, $tomorrow);
        $this->assertNotEmpty($slots1, 'store1: должны быть слоты');
        $this->assertNotEmpty($slots2, 'store2: должны быть слоты');

        // Среди слотов берём общий — выбираем 12:00 next day (точно середина для обоих).
        $slot1Ids = array_column($slots1, 'id');
        $slot2Ids = array_column($slots2, 'id');
        $common = array_values(array_intersect($slot1Ids, $slot2Ids));
        $this->assertNotEmpty($common, 'У store1 и store2 должны быть общие слоты');
        $slotId = (int) $common[count($common) / 2];

        $result = app(CreateClientOrderAction::class)->execute(new CreateClientOrderData(
            user: $user,
            addressId: $address->id,
            paymentType: 'cash',
            deliverySlots: [
                ['storeId' => $store1->id, 'slotId' => $slotId],
                ['storeId' => $store2->id, 'slotId' => $slotId],
            ],
            shippingMethod: ShippingMethod::Pickup,
        ));

        $this->assertCount(2, $result['subOrderIds']);

        $orders = Order::query()
            ->whereIn('id', $result['subOrderIds'])
            ->get();

        foreach ($orders as $order) {
            $this->assertSame(ShippingMethod::Pickup, $order->shipping_method);
            $this->assertNull($order->shipping_tariff_code);
        }
    }

    public function test_courier_path_still_creates_orders_via_delivery_slots(): void
    {
        // Регрессионный тест: старый сценарий с deliverySlots не должен ломаться.
        [$user, $address, $store1, $store2] = $this->seedCartWithTwoStores();
        $slotId = $this->getValidDeliverySlotId();

        $result = app(CreateClientOrderAction::class)->execute(new CreateClientOrderData(
            user: $user,
            addressId: $address->id,
            paymentType: 'cash',
            deliverySlots: [
                ['storeId' => $store1->id, 'slotId' => $slotId],
                ['storeId' => $store2->id, 'slotId' => $slotId],
            ],
            // shippingMethod не передан явно — должен быть default Courier.
        ));

        $this->assertCount(2, $result['subOrderIds']);

        $orders = Order::query()
            ->whereIn('id', $result['subOrderIds'])
            ->get();

        foreach ($orders as $order) {
            $this->assertSame(ShippingMethod::Courier, $order->shipping_method);
            $this->assertNull($order->shipping_tariff_code);
        }
    }

    // ==================== helpers ====================

    /**
     * @return array{0: User, 1: Address, 2: Store, 3: Store}
     */
    private function seedCartWithTwoStores(): array
    {
        $user = User::factory()->create();
        $address = Address::factory()->active()->for($user)->create();

        $store1 = Store::factory()->create([
            'min_order_amount' => 100,
            'delivery_price' => 0,
            'free_delivery_from' => null,
            'is_open_24_7' => true,
            'is_pickup_enabled' => true,
            'pickup_payment_methods' => ['cash', 'card'],
            'slot_duration' => 60,
        ]);
        $store2 = Store::factory()->create([
            'min_order_amount' => 100,
            'delivery_price' => 0,
            'free_delivery_from' => null,
            'is_open_24_7' => true,
            'is_pickup_enabled' => true,
            'pickup_payment_methods' => ['cash', 'card'],
            'slot_duration' => 60,
        ]);

        $category1 = Category::factory()->forStore($store1)->create();
        $category2 = Category::factory()->forStore($store2)->create();

        $product1 = Product::factory()->for($store1)->inCategory($category1)->create([
            'price' => 300,
            'quantity' => 10,
        ]);
        $product2 = Product::factory()->for($store2)->inCategory($category2)->create([
            'price' => 400,
            'quantity' => 10,
        ]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);

        CartItem::factory()->for($cart)->for($product1)->create([
            'store_id' => $store1->id,
            'quantity' => 2,
            'price' => 300,
        ]);
        CartItem::factory()->for($cart)->for($product2)->create([
            'store_id' => $store2->id,
            'quantity' => 1,
            'price' => 400,
        ]);

        return [$user, $address, $store1, $store2];
    }

    private function makePickupAddress(User $user, string $provider): Address
    {
        return Address::factory()->active()->for($user)->create([
            'title' => 'ПВЗ ' . strtoupper($provider),
            'city' => 'Москва',
            'street' => 'Ленина',
            'house_number' => '10',
            'latitude' => 55.7558,
            'longitude' => 37.6173,
            'additional' => [
                'kind' => 'pickup',
                'provider' => $provider,
                'external_id' => strtoupper($provider) . '-MSK-001',
                'name' => 'ПВЗ ' . strtoupper($provider) . ' Ленина 10',
            ],
        ]);
    }

    private function enableMarketplaceMode(): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    'marketplace_mode' => ['type' => 'select', 'value' => 'marketplace'],
                ], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ]
        );
        app(ModuleConfigService::class)->invalidateCache();
    }

    private function enableDeliveryModules(bool $cdek, bool $rp): void
    {
        $setting = StorefrontSetting::query()
            ->where('key', ModuleConfigService::PROJECT_MODULES_KEY)
            ->first();

        $value = $setting ? json_decode((string) $setting->value, true) : [];
        if (! is_array($value)) {
            $value = [];
        }

        $value['delivery_cdek'] = ['enabled' => $cdek];
        $value['delivery_russian_post'] = ['enabled' => $rp];
        $value['marketplace_mode'] = ['type' => 'select', 'value' => 'marketplace'];

        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode($value, JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ]
        );

        app(ModuleConfigService::class)->invalidateCache();
    }

    private function fakeSlotsApi(): FakeSlotsApiClient
    {
        $client = app(SlotsApiClientInterface::class);
        if (! $client instanceof FakeSlotsApiClient) {
            $this->fail('SlotsApiClient binding is not FakeSlotsApiClient in tests environment');
        }

        return $client;
    }
}
