<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EnablesHeavyOrdersModule;
use Tests\TestCase;

final class CreateOrderHeavyFlagTest extends TestCase
{
    use EnablesHeavyOrdersModule;
    use RefreshDatabase;

    /**
     * Юзер + активный магазин с порогом heavy_order_threshold + корзина
     * со штучным товаром заданного веса (граммы за штуку) и количеством.
     *
     * Боевой паттерн репо (см. OrderTest::test_create_order): FakeDeliveryClient /
     * FakeSlotsApiClient подставляются AppServiceProvider автоматически.
     *
     * @return array{0: User, 1: Store, 2: array<string, mixed>}
     */
    private function checkoutFixture(?int $threshold, int $weight, int $quantity): array
    {
        $user = User::factory()->create();
        $store = Store::factory()->create([
            'min_order_amount' => null,
            'delivery_price' => 0,
            'free_delivery_from' => null,
            'heavy_order_threshold' => $threshold,
        ]);
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => $quantity + 100,
            'weight' => $weight,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
        ]);
        $address = Address::factory()->active()->for($user)->create();

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => $quantity,
            'price' => 100,
        ]);

        $payload = [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
        ];

        return [$user, $store, $payload];
    }

    public function test_order_is_heavy_when_weight_exceeds_threshold(): void
    {
        $this->enableHeavyOrdersModule();
        [$user, $store, $payload] = $this->checkoutFixture(threshold: 30000, weight: 5000, quantity: 7); // 35 кг > 30 кг

        $response = $this->actingAs($user)->postJson('/api/v1/orders', $payload);

        $response->assertCreated();
        $this->assertDatabaseHas('orders', ['store_id' => $store->id, 'is_heavy' => true]);
    }

    public function test_order_not_heavy_when_weight_equals_threshold(): void
    {
        $this->enableHeavyOrdersModule();
        [$user, $store, $payload] = $this->checkoutFixture(threshold: 35000, weight: 5000, quantity: 7); // ровно 35 кг

        $this->actingAs($user)->postJson('/api/v1/orders', $payload)->assertCreated();

        $this->assertDatabaseHas('orders', ['store_id' => $store->id, 'is_heavy' => false]);
    }

    public function test_order_not_heavy_when_threshold_not_set(): void
    {
        $this->enableHeavyOrdersModule();
        [$user, $store, $payload] = $this->checkoutFixture(threshold: null, weight: 5000, quantity: 7);

        $this->actingAs($user)->postJson('/api/v1/orders', $payload)->assertCreated();

        $this->assertDatabaseHas('orders', ['store_id' => $store->id, 'is_heavy' => false]);
    }

    public function test_order_not_heavy_when_module_disabled(): void
    {
        // модуль НЕ включаем
        [$user, $store, $payload] = $this->checkoutFixture(threshold: 30000, weight: 5000, quantity: 7);

        $this->actingAs($user)->postJson('/api/v1/orders', $payload)->assertCreated();

        $this->assertDatabaseHas('orders', ['store_id' => $store->id, 'is_heavy' => false]);
    }
    /**
     * MID-259: весовая позиция (курица «1 кг», product.weight не заполнен, quantity
     * корзины в кг) должна входить в плановый вес заказа. До фикса заказ «кофе 4999 г +
     * курица 1 кг» при пороге 5 кг считался лёгким до самой сборки.
     */
    public function test_weighted_item_counts_towards_heavy_flag_at_checkout(): void
    {
        $this->enableHeavyOrdersModule();
        [$user, $store, $payload] = $this->checkoutFixture(threshold: 5000, weight: 4999, quantity: 1);

        $category = Category::factory()->forStore($store)->create();
        $chicken = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 300,
            'quantity' => 100000, // остаток витрины весового — в граммах (100 кг)
            'weight' => null,
            'quantity_step' => 1,
            'measurement_unit' => 'kilogram',
        ]);
        CartItem::factory()->for(Cart::where('user_id', $user->id)->firstOrFail())->for($chicken)->create([
            'store_id' => $store->id,
            'quantity' => 1, // 1 кг
            'price' => 300,
        ]);

        $this->actingAs($user)->postJson('/api/v1/orders', $payload)->assertCreated();

        $this->assertDatabaseHas('orders', ['store_id' => $store->id, 'is_heavy' => true]); // 4999 + 1000 > 5000
    }
}
