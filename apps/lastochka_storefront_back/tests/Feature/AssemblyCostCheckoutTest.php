<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Resources\Api\V1\CartStoreGroupResource;
use App\Http\Resources\Api\V1\ClientOrderResource;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\Delivery\Contracts\DeliveryClientInterface;
use App\Services\Delivery\FakeDeliveryClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class AssemblyCostCheckoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Edit #1: ресурс группы прокидывает assemblyCost из массива группы.
     */
    public function test_group_resource_exposes_assembly_cost(): void
    {
        $store = Store::factory()->create(['assembly_price' => 120]);

        $resource = (new CartStoreGroupResource([
            'id' => 1, 'store' => $store, 'items' => collect(),
            'deliveryCost' => 0, 'minOrderAmount' => 0, 'freeDeliveryFrom' => null,
            'discount' => 0, 'promocode' => null, 'bonus' => null,
            'isHeavy' => false, 'availableFrom' => null,
            'assemblyCost' => 120,
        ]))->toArray(Request::create('/'));

        $this->assertSame(120, $resource['assemblyCost']);
    }

    /**
     * Edit #1: отсутствие ключа assemblyCost → 0 (fallback), не ошибка.
     */
    public function test_group_resource_assembly_cost_defaults_to_zero(): void
    {
        $store = Store::factory()->create(['assembly_price' => 120]);

        $resource = (new CartStoreGroupResource([
            'id' => 1, 'store' => $store, 'items' => collect(),
            'deliveryCost' => 0, 'minOrderAmount' => 0, 'freeDeliveryFrom' => null,
            'discount' => 0, 'promocode' => null, 'bonus' => null,
            'isHeavy' => false, 'availableFrom' => null,
        ]))->toArray(Request::create('/'));

        $this->assertSame(0, $resource['assemblyCost']);
    }

    /**
     * Edit #2: при включённом флаге assemblyCost группы = store.assembly_price.
     */
    public function test_cart_group_exposes_assembly_price_when_flag_enabled(): void
    {
        $this->enableAssemblyCostModule();
        [$user] = $this->cartFixture(assemblyPrice: 250);

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk()
            ->assertJsonPath('storeGroups.0.assemblyCost', 250);
    }

    /**
     * Edit #2: при выключенном флаге assemblyCost = 0, даже если price задан.
     */
    public function test_cart_group_assembly_cost_zero_when_flag_disabled(): void
    {
        // Модуль НЕ включаем: assembly_price задан, но признак должен быть 0.
        [$user] = $this->cartFixture(assemblyPrice: 250);

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk()
            ->assertJsonPath('storeGroups.0.assemblyCost', 0);
    }

    /**
     * Edit #2: флаг включён, но assembly_price = null → 0.
     */
    public function test_cart_group_assembly_cost_zero_when_price_null(): void
    {
        $this->enableAssemblyCostModule();
        [$user] = $this->cartFixture(assemblyPrice: null);

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk()
            ->assertJsonPath('storeGroups.0.assemblyCost', 0);
    }

    /**
     * Зонный fallback: курьерская доставка с адресом → assemblyCost из slots-api
     * (DeliveryInfoData::assemblyPrice), приоритет над магазинной ценой.
     */
    public function test_cart_group_uses_zone_assembly_price_for_courier_with_address(): void
    {
        $this->enableAssemblyCostModule();
        [$user, $address] = $this->courierCartFixture(storeAssemblyPrice: 100);
        $this->setZoneAssemblyPrice(250);

        $response = $this->actingAs($user)->getJson("/api/v1/cart?addressId={$address->id}");

        $response->assertOk()
            ->assertJsonPath('storeGroups.0.assemblyCost', 250);
    }

    /**
     * Зонный fallback: зона без своей цены (assemblyPrice=null) → магазинная
     * $store->assembly_price (текущее поведение).
     */
    public function test_cart_group_falls_back_to_store_assembly_price_when_zone_null(): void
    {
        $this->enableAssemblyCostModule();
        [$user, $address] = $this->courierCartFixture(storeAssemblyPrice: 100);
        $this->setZoneAssemblyPrice(null);

        $response = $this->actingAs($user)->getJson("/api/v1/cart?addressId={$address->id}");

        $response->assertOk()
            ->assertJsonPath('storeGroups.0.assemblyCost', 100);
    }

    /**
     * Семантическая граница: зона с assembly_price=0 даёт assemblyCost=0, а НЕ
     * fallback на магазинную. `??` срабатывает только на null, не на 0.
     */
    public function test_cart_group_zone_assembly_price_zero_is_not_store_fallback(): void
    {
        $this->enableAssemblyCostModule();
        [$user, $address] = $this->courierCartFixture(storeAssemblyPrice: 100);
        $this->setZoneAssemblyPrice(0);

        $response = $this->actingAs($user)->getJson("/api/v1/cart?addressId={$address->id}");

        $response->assertOk()
            ->assertJsonPath('storeGroups.0.assemblyCost', 0);
    }

    /**
     * Edit #3: OrderResource отдаёт assemblyCost из persisted orders.assembly_cost.
     */
    public function test_order_resource_exposes_assembly_cost(): void
    {
        $order = Order::factory()->create(['assembly_cost' => 90]);

        $arr = (new OrderResource($order))->toArray(Request::create('/'));

        $this->assertSame(90.0, $arr['assemblyCost']);
    }

    /**
     * Edit #4a: ClientOrderResource агрегирует assemblyCost как сумму по саб-заказам.
     */
    public function test_client_order_resource_aggregates_assembly_cost(): void
    {
        $order1 = Order::factory()->create(['assembly_cost' => 90]);
        $order2 = Order::factory()->create(['assembly_cost' => 60]);

        $arr = (new ClientOrderResource('co-test', collect([$order1, $order2])))
            ->toArray(Request::create('/'));

        $this->assertSame(150.0, $arr['assemblyCost']);
    }

    /**
     * Edit #4b: ClientOrderResource отдаёт assemblyCost per-order внутри subOrders[].
     */
    public function test_client_order_resource_exposes_per_order_assembly_cost(): void
    {
        $order1 = Order::factory()->create(['assembly_cost' => 90]);
        $order2 = Order::factory()->create(['assembly_cost' => 60]);

        $arr = (new ClientOrderResource('co-test', collect([$order1, $order2])))
            ->toArray(Request::create('/'));

        $this->assertSame(90.0, $arr['subOrders'][0]['assemblyCost']);
        $this->assertSame(60.0, $arr['subOrders'][1]['assemblyCost']);
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
     * Юзер + курьерский магазин + активный адрес с координатами + корзина.
     * Курьерская доставка с адресом даёт зонный контекст (DeliveryInfoData).
     *
     * @return array{0: User, 1: Address}
     */
    private function courierCartFixture(?int $storeAssemblyPrice): array
    {
        $user = User::factory()->create();
        $address = Address::factory()->active()->for($user)->create();

        $store = Store::factory()->create([
            'min_order_amount' => null,
            'delivery_price' => 0,
            'free_delivery_from' => null,
            'is_bonus_enabled' => false,
            'assembly_price' => $storeAssemblyPrice,
        ]);
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => 105,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
        ]);

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 2,
            'price' => 100,
        ]);

        return [$user, $address];
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
     * Юзер + магазин с assembly_price + корзина со штучным товаром.
     * Паттерн фикстуры совпадает с CartHeavyFlagTest.
     *
     * @return array{0: User, 1: Store}
     */
    private function cartFixture(?int $assemblyPrice): array
    {
        $user = User::factory()->create();
        $store = Store::factory()->create([
            'min_order_amount' => null,
            'delivery_price' => 0,
            'free_delivery_from' => null,
            'is_bonus_enabled' => false,
            'assembly_price' => $assemblyPrice,
        ]);
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => 105,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
        ]);

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 2,
            'price' => 100,
        ]);

        return [$user, $store];
    }
}
