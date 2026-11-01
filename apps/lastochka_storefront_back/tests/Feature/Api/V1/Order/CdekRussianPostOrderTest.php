<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Order;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use App\Services\SlotsApi\FakeSlotsApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Тесты создания заказов с методами доставки СДЭК и Почта России.
 */
final class CdekRussianPostOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // По умолчанию мочим slots-api тарифы — иначе ThirdPartyTariffPriceResolver
        // ловит 422 «тариф недоступен» и тесты падают на ровном месте.
        $fake = app(SlotsApiClientInterface::class);
        if ($fake instanceof FakeSlotsApiClient) {
            $fake->setCdekTariffs([
                ['code' => '136', 'name' => 'Склад-Склад', 'price' => 350, 'currency' => 'RUB'],
                ['code' => '139', 'name' => 'Склад-Дверь', 'price' => 420, 'currency' => 'RUB'],
            ]);
            $fake->setRpTariffs([
                ['code' => 'online-parcel', 'name' => 'Посылка онлайн', 'price' => 280, 'currency' => 'RUB'],
            ]);
        }
    }

    // ==================== Вспомогательные методы ====================

    /**
     * Создать адрес ПВЗ СДЭК для пользователя.
     */
    private function makeCdekPickupAddress(User $user, array $additionalOverride = []): Address
    {
        return Address::factory()->for($user)->create([
            'title' => 'ПВЗ СДЭК',
            'city' => 'Москва',
            'street' => 'Ленина',
            'house_number' => '10',
            'latitude' => 55.7558,
            'longitude' => 37.6173,
            'is_active' => true,
            'additional' => array_merge([
                'kind' => 'pickup',
                'provider' => 'cdek',
                'external_id' => 'CDEK-MSK-001',
                'name' => 'ПВЗ СДЭК Ленина 10',
                'raw' => ['some' => 'raw-data'],
            ], $additionalOverride),
        ]);
    }

    /**
     * Создать адрес ПВЗ Почты России для пользователя.
     */
    private function makeRussianPostPickupAddress(User $user, array $additionalOverride = []): Address
    {
        return Address::factory()->for($user)->create([
            'title' => 'ПВЗ Почта России',
            'city' => 'Москва',
            'street' => 'Тверская',
            'house_number' => '5',
            'latitude' => 55.7600,
            'longitude' => 37.6200,
            'is_active' => true,
            'additional' => array_merge([
                'kind' => 'pickup',
                'provider' => 'russian_post',
                'external_id' => 'RP-MSK-101200',
                'name' => 'Почтовое отделение №1',
                'raw' => ['index' => '101200'],
            ], $additionalOverride),
        ]);
    }

    /**
     * Создать базовый магазин + категорию + товар в корзине.
     */
    private function setupStoreWithCartItem(User $user): array
    {
        $store = Store::factory()->create([
            'min_order_amount' => null,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 500,
            'quantity' => 10,
        ]);
        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 500,
        ]);

        return compact('store', 'category', 'product', 'cart');
    }

    // ==================== Тесты СДЭК ====================

    public function test_creates_order_with_cdek_shipping_method(): void
    {
        $user = User::factory()->create();
        ['store' => $store, 'product' => $product] = $this->setupStoreWithCartItem($user);
        $address = $this->makeCdekPickupAddress($user);

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'shippingMethod' => 'cdek',
            'shippingTariffCode' => '136',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['orderId']);

        $orderId = $response->json('orderId');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'user_id' => $user->id,
            'store_id' => $store->id,
            'status' => 'pending',
            'shipping_method' => 'cdek',
            'shipping_tariff_code' => '136',
            'pickup_external_id' => 'CDEK-MSK-001',
            'delivery_cost' => 350,
        ]);

        // pickup_address_data — snapshot additional-поля адреса
        $order = \App\Models\Order::find($orderId);
        $this->assertNotNull($order->pickup_address_data);
        $this->assertEquals('pickup', $order->pickup_address_data['kind']);
        $this->assertEquals('cdek', $order->pickup_address_data['provider']);
        $this->assertEquals('CDEK-MSK-001', $order->pickup_address_data['external_id']);
    }

    public function test_cdek_order_stores_full_additional_as_snapshot(): void
    {
        $user = User::factory()->create();
        ['store' => $store] = $this->setupStoreWithCartItem($user);

        $additionalData = [
            'kind' => 'pickup',
            'provider' => 'cdek',
            'external_id' => 'CDEK-SPB-999',
            'name' => 'ПВЗ у метро',
            'raw' => ['code' => 'SPB-999', 'city_code' => 190],
        ];
        $address = $this->makeCdekPickupAddress($user, $additionalData);

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'shippingMethod' => 'cdek',
            'shippingTariffCode' => '139',
        ]);

        $response->assertCreated();

        $order = \App\Models\Order::find($response->json('orderId'));
        $this->assertEquals('CDEK-SPB-999', $order->pickup_address_data['external_id']);
        $this->assertEquals('SPB-999', $order->pickup_address_data['raw']['code']);
        $this->assertEquals('139', $order->shipping_tariff_code);
    }

    public function test_rejects_cdek_order_when_address_is_not_pickup(): void
    {
        $user = User::factory()->create();
        ['store' => $store] = $this->setupStoreWithCartItem($user);

        // Обычный домашний адрес — без additional.kind=pickup
        $address = Address::factory()->active()->for($user)->create();

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'shippingMethod' => 'cdek',
            'shippingTariffCode' => '136',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['addressId']);
    }

    public function test_rejects_cdek_order_when_provider_mismatch(): void
    {
        $user = User::factory()->create();
        ['store' => $store] = $this->setupStoreWithCartItem($user);

        // Адрес ПВЗ, но провайдер russian_post, а метод cdek
        $address = $this->makeRussianPostPickupAddress($user);

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'shippingMethod' => 'cdek',
            'shippingTariffCode' => '136',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['addressId']);
    }

    public function test_rejects_cdek_order_without_tariff_code(): void
    {
        $user = User::factory()->create();
        ['store' => $store] = $this->setupStoreWithCartItem($user);
        $address = $this->makeCdekPickupAddress($user);

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'shippingMethod' => 'cdek',
            // shippingTariffCode не передан
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['shippingTariffCode']);
    }

    public function test_cdek_order_does_not_require_delivery_slot(): void
    {
        $user = User::factory()->create();
        ['store' => $store] = $this->setupStoreWithCartItem($user);
        $address = $this->makeCdekPickupAddress($user);

        // Слот не передаём — для ПВЗ он не обязателен
        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'shippingMethod' => 'cdek',
            'shippingTariffCode' => '136',
        ]);

        $response->assertCreated();
    }

    // ==================== Тесты Почты России ====================

    public function test_creates_order_with_russian_post_shipping_method(): void
    {
        $user = User::factory()->create();
        ['store' => $store, 'product' => $product] = $this->setupStoreWithCartItem($user);
        $address = $this->makeRussianPostPickupAddress($user);

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'shippingMethod' => 'russian_post',
            'shippingTariffCode' => 'online-parcel',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['orderId']);

        $orderId = $response->json('orderId');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'shipping_method' => 'russian_post',
            'shipping_tariff_code' => 'online-parcel',
            'pickup_external_id' => 'RP-MSK-101200',
            'delivery_cost' => 280,
        ]);

        $order = \App\Models\Order::find($orderId);
        $this->assertNotNull($order->pickup_address_data);
        $this->assertEquals('russian_post', $order->pickup_address_data['provider']);
        $this->assertEquals('101200', $order->pickup_address_data['raw']['index']);
    }

    public function test_rejects_russian_post_order_when_address_is_not_pickup(): void
    {
        $user = User::factory()->create();
        ['store' => $store] = $this->setupStoreWithCartItem($user);

        // Обычный адрес без additional
        $address = Address::factory()->active()->for($user)->create();

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'shippingMethod' => 'russian_post',
            'shippingTariffCode' => 'online-parcel',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['addressId']);
    }

    public function test_rejects_russian_post_order_without_tariff_code(): void
    {
        $user = User::factory()->create();
        ['store' => $store] = $this->setupStoreWithCartItem($user);
        $address = $this->makeRussianPostPickupAddress($user);

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'shippingMethod' => 'russian_post',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['shippingTariffCode']);
    }

    // ==================== Прочие методы доставки не сломаны ====================

    public function test_shipping_method_in_validation_rejects_unknown_value(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => 1,
            'addressId' => 1,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
            'shippingMethod' => 'unknown_method',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['shippingMethod']);
    }

    public function test_courier_order_still_requires_delivery_slot(): void
    {
        $user = User::factory()->create();
        ['store' => $store] = $this->setupStoreWithCartItem($user);
        $address = Address::factory()->active()->for($user)->create();

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'shippingMethod' => 'courier',
            // deliverySlotId не передан
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['deliverySlotId']);
    }
}
