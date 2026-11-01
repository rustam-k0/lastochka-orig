<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EnablesHeavyOrdersModule;
use Tests\TestCase;

final class CartHeavyFlagTest extends TestCase
{
    use EnablesHeavyOrdersModule;
    use RefreshDatabase;

    /**
     * Юзер + магазин с порогом heavy_order_threshold + корзина со штучным
     * товаром (weight грамм за штуку × quantity). Стандартный режим корзины
     * (не marketplace) → CartResource со storeGroups.
     *
     * Паттерн фикстуры совпадает с CartWeightTotalTest / CreateOrderHeavyFlagTest.
     *
     * @return array{0: User, 1: Store}
     */
    private function cartFixture(?int $threshold, int $weight, int $quantity): array
    {
        $user = User::factory()->create();
        $store = Store::factory()->create([
            'min_order_amount' => null,
            'delivery_price' => 0,
            'free_delivery_from' => null,
            'is_bonus_enabled' => false,
            'heavy_order_threshold' => $threshold,
        ]);
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => $quantity + 100,
            'quantity_step' => 1,
            'weight' => $weight,
            'measurement_unit' => 'piece',
        ]);

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => $quantity,
            'price' => 100,
        ]);

        return [$user, $store];
    }

    public function test_cart_group_and_top_level_is_heavy(): void
    {
        $this->enableHeavyOrdersModule();
        [$user] = $this->cartFixture(threshold: 30000, weight: 5000, quantity: 7); // 35 кг > 30 кг

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk()
            ->assertJsonPath('isHeavy', true)
            ->assertJsonPath('storeGroups.0.isHeavy', true);
    }

    public function test_cart_is_heavy_false_below_threshold(): void
    {
        $this->enableHeavyOrdersModule();
        [$user] = $this->cartFixture(threshold: 30000, weight: 5000, quantity: 5); // 25 кг < 30 кг

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk()
            ->assertJsonPath('isHeavy', false)
            ->assertJsonPath('storeGroups.0.isHeavy', false);
    }

    public function test_cart_is_heavy_false_when_module_disabled(): void
    {
        // Модуль НЕ включаем: вес превышает порог, но признак должен быть false.
        [$user] = $this->cartFixture(threshold: 30000, weight: 5000, quantity: 7); // 35 кг

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk()
            ->assertJsonPath('isHeavy', false)
            ->assertJsonPath('storeGroups.0.isHeavy', false);
    }
}
