<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\IntegrationConfig;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Delivery\Contracts\DeliveryClientInterface;
use App\Services\Delivery\FakeDeliveryClient;
use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use App\Services\SlotsApi\FakeSlotsApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TogglesDeliveryPriceRangesModule;
use Tests\TestCase;

/**
 * MID-280: поля API витрины для ступеней доставки (camelCase):
 * deliveryPriceRanges [{fromAmount, price}] и nextDeliveryStep {fromAmount, price, amountLeft}.
 * Существующие поля не меняются.
 */
final class DeliveryPriceRangesApiTest extends TestCase
{
    use RefreshDatabase;
    use TogglesDeliveryPriceRangesModule;

    protected function setUp(): void
    {
        parent::setUp();
        // Тесты написаны до тумблера (Task 10): модуль включён, поведение прежнее.
        $this->setDeliveryPriceRangesModule(true);
    }

    private const RANGES = [
        ['from_amount' => 1000, 'price' => 100],
        ['from_amount' => 3000, 'price' => 0],
    ];

    private const API_RANGES = [
        ['fromAmount' => 1000, 'price' => 100],
        ['fromAmount' => 3000, 'price' => 0],
    ];

    /**
     * @return array{0: User, 1: Store, 2: Address|null}
     */
    private function cart(float $sum, bool $withAddress): array
    {
        $user = User::factory()->create();
        $address = $withAddress
            ? Address::factory()->active()->for($user)->create(['latitude' => 55.7558, 'longitude' => 37.6173])
            : null;

        $store = Store::factory()->create([
            'code' => 'shop-api',
            'delivery_price' => 200,
            'free_delivery_from' => 3000,
            'delivery_price_ranges' => self::RANGES,
            'min_order_amount' => 0,
            'is_pickup_enabled' => true,
        ]);
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create(['price' => $sum, 'quantity' => 10]);
        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->for($store)->create(['quantity' => 1, 'price' => $sum]);

        /** @var FakeSlotsApiClient $slots */
        $slots = $this->app->make(SlotsApiClientInterface::class);
        $slots->setShops([[
            'id' => 1,
            'code' => 'shop-api',
            'delivery_price' => 200,
            'min_order_price' => 0,
            'free_delivery_from' => 3000,
            'delivery_price_ranges' => self::RANGES,
        ]]);

        return [$user, $store, $address];
    }

    /**
     * Фикс-раунд 1: корзина с зоной на Яндекс-доставке (CartResource, ветка
     * `$fullInfo->deliveryType === 'yandex'`). У зоны Яндекса платных ступеней
     * не бывает (валидатор admin_back их не пропускает) — только бесплатная.
     *
     * @return array{0: User, 1: Store, 2: Address}
     */
    private function yandexCart(float $sum): array
    {
        $user = User::factory()->create();
        $address = Address::factory()->active()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
            'house_number' => '1',
        ]);

        $store = Store::factory()->create([
            'code' => 'shop-yandex',
            'latitude' => 55.75,
            'longitude' => 37.61,
            'min_order_amount' => 0,
            'is_pickup_enabled' => true,
        ]);
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create(['price' => $sum, 'quantity' => 10]);
        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->for($store)->create(['quantity' => 1, 'price' => $sum]);

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

        /** @var FakeDeliveryClient $client */
        $client = app(DeliveryClientInterface::class);
        $client->deliveryType = 'yandex';
        $client->deliveryPriceRanges = [['from_amount' => 3000, 'price' => 0]];

        return [$user, $store, $address];
    }

    public function test_cart_yandex_zone_has_only_free_step_below_threshold(): void
    {
        // FakeYandexGoClient::$price = 507.52 → priceRounded() = 508 (ceil)
        [$user] = $this->yandexCart(2500);

        $this->actingAs($user)->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('storeGroups.0.deliveryCost', 508)
            ->assertJsonPath('storeGroups.0.store.deliveryInfo.deliveryPriceRanges', [['fromAmount' => 3000, 'price' => 0]])
            ->assertJsonPath('storeGroups.0.nextDeliveryStep.fromAmount', 3000)
            ->assertJsonPath('storeGroups.0.nextDeliveryStep.price', 0)
            ->assertJsonPath('storeGroups.0.nextDeliveryStep.amountLeft', 500);
    }

    public function test_cart_yandex_zone_is_free_from_threshold(): void
    {
        [$user] = $this->yandexCart(3000);

        $this->actingAs($user)->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('storeGroups.0.deliveryCost', 0)
            ->assertJsonPath('storeGroups.0.store.deliveryInfo.deliveryPriceRanges', [['fromAmount' => 3000, 'price' => 0]])
            ->assertJsonPath('storeGroups.0.nextDeliveryStep', null);
    }

    /**
     * Фикс-раунд 1: /stores/nearest — StoreResource::withDeliveryInfo() без ключа
     * deliveryPriceRanges (контроллер его не передаёт), запасной путь читает
     * ступени из Store::deliveryLadder() (модель, не адрес/зона).
     */
    public function test_nearest_store_has_ranges_from_store_ladder(): void
    {
        $store = Store::factory()->create([
            'latitude' => 56.0124,
            'longitude' => 92.8553,
            'delivery_price' => 150,
            'delivery_price_ranges' => self::RANGES,
            'free_delivery_from' => 3000,
        ]);

        $this->getJson('/api/v1/stores/nearest?latitude=56.0124&longitude=92.8553')
            ->assertOk()
            ->assertJsonPath('data.id', $store->id)
            ->assertJsonPath('data.deliveryInfo.deliveryPrice', 150)
            ->assertJsonPath('data.deliveryInfo.deliveryPriceRanges', self::API_RANGES)
            // freeDeliveryFrom этой ручки — прежнее поведение (не передавалось
            // контроллером и до MID-280): null независимо от порога магазина.
            ->assertJsonPath('data.deliveryInfo.freeDeliveryFrom', null);
    }

    public function test_nearest_store_without_ranges_shows_free_threshold_step(): void
    {
        $store = Store::factory()->create([
            'latitude' => 56.0124,
            'longitude' => 92.8553,
            'delivery_price' => 150,
            'delivery_price_ranges' => [],
            'free_delivery_from' => 2500,
        ]);

        $this->getJson('/api/v1/stores/nearest?latitude=56.0124&longitude=92.8553')
            ->assertOk()
            ->assertJsonPath('data.id', $store->id)
            ->assertJsonPath('data.deliveryInfo.deliveryPriceRanges', [['fromAmount' => 2500, 'price' => 0]])
            ->assertJsonPath('data.deliveryInfo.freeDeliveryFrom', null);
    }

    /**
     * Фикс-раунд 1: полностью пустая лесенка (нет ступеней и нет порога) —
     * deliveryPriceRanges: []. У /delivery-slots и /delivery/calculate нет
     * поля nextDeliveryStep в контракте (только у корзины), поэтому не проверяется.
     */
    public function test_delivery_slots_without_ranges_and_without_threshold_is_empty(): void
    {
        [$user, $store, $address] = $this->cart(660, true);
        /** @var FakeDeliveryClient $client */
        $client = app(DeliveryClientInterface::class);
        $client->deliveryPriceRanges = [];
        $client->freeDeliveryFrom = null;

        $date = now()->addDay()->format('Y-m-d');

        $this->actingAs($user)->getJson("/api/v1/stores/{$store->id}/delivery-slots?addressId={$address->id}&date={$date}")
            ->assertOk()
            ->assertJsonPath('deliveryPriceRanges', []);
    }

    public function test_cart_group_has_ranges_and_next_step(): void
    {
        [$user] = $this->cart(660, true);

        $this->actingAs($user)->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('storeGroups.0.deliveryCost', 200)
            ->assertJsonPath('storeGroups.0.freeDeliveryFrom', 3000)
            ->assertJsonPath('storeGroups.0.store.deliveryInfo.deliveryPrice', 200)
            ->assertJsonPath('storeGroups.0.store.deliveryInfo.freeDeliveryFrom', 3000)
            ->assertJsonPath('storeGroups.0.store.deliveryInfo.deliveryPriceRanges', self::API_RANGES)
            ->assertJsonPath('storeGroups.0.nextDeliveryStep.fromAmount', 1000)
            ->assertJsonPath('storeGroups.0.nextDeliveryStep.price', 100)
            ->assertJsonPath('storeGroups.0.nextDeliveryStep.amountLeft', 340);
    }

    public function test_cart_next_step_is_null_when_free(): void
    {
        [$user] = $this->cart(3000, true);

        $response = $this->actingAs($user)->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('storeGroups.0.deliveryCost', 0)
            ->assertJsonPath('storeGroups.0.nextDeliveryStep', null);

        $this->assertArrayHasKey('nextDeliveryStep', $response->json('storeGroups.0'));
    }

    public function test_cart_without_address_uses_store_ranges(): void
    {
        [$user] = $this->cart(1500, false);

        $this->actingAs($user)->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('storeGroups.0.store.deliveryInfo.deliveryPriceRanges', self::API_RANGES)
            ->assertJsonPath('storeGroups.0.nextDeliveryStep.fromAmount', 3000)
            ->assertJsonPath('storeGroups.0.nextDeliveryStep.amountLeft', 1500);
    }

    public function test_cart_pickup_has_no_ranges(): void
    {
        [$user] = $this->cart(660, false);

        $this->actingAs($user)->getJson('/api/v1/cart?shippingMethod=pickup')
            ->assertOk()
            ->assertJsonPath('storeGroups.0.store.deliveryInfo.deliveryPriceRanges', [])
            ->assertJsonPath('storeGroups.0.nextDeliveryStep', null);
    }

    public function test_delivery_slots_have_zone_ranges(): void
    {
        [$user, $store, $address] = $this->cart(660, true);
        /** @var FakeDeliveryClient $client */
        $client = app(DeliveryClientInterface::class);
        $client->deliveryPriceRanges = self::RANGES;

        $date = now()->addDay()->format('Y-m-d');

        $this->actingAs($user)->getJson("/api/v1/stores/{$store->id}/delivery-slots?addressId={$address->id}&date={$date}")
            ->assertOk()
            ->assertJsonPath('deliveryPrice', 200)
            ->assertJsonPath('freeDeliveryFrom', 2000)
            ->assertJsonPath('deliveryPriceRanges', self::API_RANGES);
    }

    public function test_delivery_slots_without_ranges_show_free_threshold_step(): void
    {
        [$user, $store, $address] = $this->cart(660, true);
        $date = now()->addDay()->format('Y-m-d');

        // FakeDeliveryClient без ступеней: порог 2000 даёт одну бесплатную ступень
        $this->actingAs($user)->getJson("/api/v1/stores/{$store->id}/delivery-slots?addressId={$address->id}&date={$date}")
            ->assertOk()
            ->assertJsonPath('deliveryPriceRanges', [['fromAmount' => 2000, 'price' => 0]]);
    }

    public function test_delivery_calculate_own_courier_has_ranges(): void
    {
        [$user, $store, $address] = $this->cart(660, true);
        /** @var FakeDeliveryClient $client */
        $client = app(DeliveryClientInterface::class);
        $client->deliveryPriceRanges = self::RANGES;

        $this->actingAs($user)
            ->getJson("/api/v1/delivery/calculate?shippingMethod=own_courier&shopId={$store->id}&addressId={$address->id}")
            ->assertOk()
            ->assertJsonPath('data.deliveryPrice', 200)
            ->assertJsonPath('data.deliveryPriceRanges', self::API_RANGES);
    }

    public function test_stores_by_address_have_zone_ranges(): void
    {
        [$user, $store, $address] = $this->cart(660, true);

        $this->actingAs($user)->getJson("/api/v1/stores?addressId={$address->id}")
            ->assertOk()
            ->assertJsonPath('data.0.id', $store->id)
            ->assertJsonPath('data.0.deliveryInfo.deliveryPriceRanges', self::API_RANGES);

        $this->actingAs($user)->getJson("/api/v1/stores/{$store->id}?addressId={$address->id}")
            ->assertOk()
            ->assertJsonPath('data.deliveryInfo.deliveryPriceRanges', self::API_RANGES);
    }

    public function test_stores_without_address_have_store_ranges(): void
    {
        [$user, $store] = $this->cart(660, false);

        $this->getJson('/api/v1/stores')
            ->assertOk()
            ->assertJsonPath('data.0.id', $store->id)
            ->assertJsonPath('data.0.deliveryInfo.deliveryPriceRanges', self::API_RANGES);

        $this->getJson("/api/v1/stores/{$store->id}")
            ->assertOk()
            ->assertJsonPath('data.deliveryInfo.deliveryPriceRanges', self::API_RANGES);
    }
}
