<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TogglesPriceTiersModule;
use Tests\TestCase;

final class CartPriceTiersTest extends TestCase
{
    use RefreshDatabase;
    use TogglesPriceTiersModule;

    private const FROM_ONE = [
        ['min_quantity' => 1, 'price' => 279],
        ['min_quantity' => 2, 'price' => 249],
        ['min_quantity' => 5, 'price' => 199],
    ];

    private User $user;

    private Store $store;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create();
        $category = Category::factory()->forStore($this->store)->create();
        $this->product = Product::factory()->for($this->store)->inCategory($category)->create([
            'price' => 279,
            'price_old' => null,
            'quantity' => 100,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
        ]);
    }

    private function add(int $quantity): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->postJson('/api/v1/cart/items', [
            'productId' => $this->product->id,
            'quantity' => $quantity,
        ]);
    }

    private function item(): CartItem
    {
        return CartItem::where('product_id', $this->product->id)->whereNull('parent_id')->firstOrFail();
    }

    public function test_add_uses_tier_for_added_quantity(): void
    {
        $this->setPriceTiersModule(true);
        $this->seedPriceTiers($this->product, $this->store->id, self::FROM_ONE);

        $response = $this->add(5)->assertOk();

        $this->assertEquals(199.0, (float) $response->json('storeGroups.0.items.0.price'));
        $this->assertEquals(279.0, (float) $response->json('storeGroups.0.items.0.priceOld'));
        $this->assertDatabaseHas('cart_items', ['id' => $this->item()->id, 'quantity' => 5, 'price' => 199, 'price_old' => 279]);
    }

    /** Слияние в корзине: ступень считается по суммарному количеству позиции. */
    public function test_merge_switches_tier_by_total_quantity(): void
    {
        $this->setPriceTiersModule(true);
        $this->seedPriceTiers($this->product, $this->store->id, self::FROM_ONE);

        $first = $this->add(1)->assertOk();
        $this->assertEquals(279.0, (float) $first->json('storeGroups.0.items.0.price'));
        $this->assertNull($first->json('storeGroups.0.items.0.priceOld'), 'первая ступень: без зачёркнутой цены');

        $second = $this->add(1)->assertOk();
        $this->assertSame(2, $second->json('storeGroups.0.items.0.quantity'));
        $this->assertEquals(249.0, (float) $second->json('storeGroups.0.items.0.price'));
        $this->assertEquals(279.0, (float) $second->json('storeGroups.0.items.0.priceOld'));

        $third = $this->add(3)->assertOk();
        $this->assertSame(5, $third->json('storeGroups.0.items.0.quantity'));
        $this->assertEquals(199.0, (float) $third->json('storeGroups.0.items.0.price'));
    }

    public function test_update_quantity_moves_between_tiers_both_ways(): void
    {
        $this->setPriceTiersModule(true);
        $this->seedPriceTiers($this->product, $this->store->id, self::FROM_ONE);
        $this->add(1)->assertOk();
        $itemId = $this->item()->id;

        $up = $this->actingAs($this->user)->putJson("/api/v1/cart/items/{$itemId}", ['quantity' => 5])->assertOk();
        $this->assertEquals(199.0, (float) $up->json('storeGroups.0.items.0.price'));
        $this->assertEquals(279.0, (float) $up->json('storeGroups.0.items.0.priceOld'));

        $down = $this->actingAs($this->user)->putJson("/api/v1/cart/items/{$itemId}", ['quantity' => 1])->assertOk();
        $this->assertEquals(279.0, (float) $down->json('storeGroups.0.items.0.price'));
        $this->assertNull($down->json('storeGroups.0.items.0.priceOld'));
    }

    /** syncPrices на выдаче корзины не затирает ступень обычной ценой товара. */
    public function test_get_cart_does_not_wipe_tier_price(): void
    {
        $this->setPriceTiersModule(true);
        $this->seedPriceTiers($this->product, $this->store->id, self::FROM_ONE);
        $this->add(5)->assertOk();

        $response = $this->actingAs($this->user)->getJson('/api/v1/cart')->assertOk();

        $this->assertEquals(199.0, (float) $response->json('storeGroups.0.items.0.price'));
        $this->assertEquals(279.0, (float) $response->json('storeGroups.0.items.0.priceOld'));
        $this->assertDatabaseHas('cart_items', ['id' => $this->item()->id, 'price' => 199, 'price_old' => 279]);
    }

    /** syncPrices подхватывает лесенку, появившуюся после добавления в корзину. */
    public function test_get_cart_applies_ladder_that_arrived_later(): void
    {
        $this->setPriceTiersModule(true);
        $this->add(5)->assertOk();
        $this->assertDatabaseHas('cart_items', ['id' => $this->item()->id, 'price' => 279]);

        $this->seedPriceTiers($this->product, $this->store->id, self::FROM_ONE);

        $response = $this->actingAs($this->user)->getJson('/api/v1/cart')->assertOk();

        $this->assertEquals(199.0, (float) $response->json('storeGroups.0.items.0.price'));
    }

    public function test_quantum_product_counts_portions_not_grams(): void
    {
        $this->setPriceTiersModule(true);
        $this->product->update(['unit_type' => 'kg', 'measurement_unit' => 'gram', 'quantity_step' => 200, 'price' => 100, 'quantity' => 100000]);
        $this->seedPriceTiers($this->product, $this->store->id, [['min_quantity' => 2, 'price' => 450]]);

        $nine = $this->add(1800)->assertOk();
        $this->assertEquals(100.0, (float) $nine->json('storeGroups.0.items.0.price'), '9 порций: ступень «от 2 кг» не достигнута');

        $ten = $this->add(200)->assertOk();
        $this->assertSame(2000, $ten->json('storeGroups.0.items.0.quantity'));
        $this->assertEquals(90.0, (float) $ten->json('storeGroups.0.items.0.price'), '10 порций = 2 кг: 450 ₽/кг = 90 ₽ за 200 г');
        $this->assertEquals(100.0, (float) $ten->json('storeGroups.0.items.0.priceOld'));
    }

    public function test_child_supplement_item_is_not_tiered(): void
    {
        $this->setPriceTiersModule(true);
        $sauce = Product::factory()->for($this->store)->create(['price' => 50, 'quantity' => 100, 'quantity_step' => 1]);
        $this->seedPriceTiers($sauce, $this->store->id, [['min_quantity' => 1, 'price' => 40]]);

        $cart = Cart::create(['user_id' => $this->user->id]);
        $parent = $cart->items()->create([
            'product_id' => $this->product->id, 'store_id' => $this->store->id, 'quantity' => 5, 'price' => 279,
        ]);
        $child = $cart->items()->create([
            'product_id' => $sauce->id, 'store_id' => $this->store->id, 'quantity' => 5, 'price' => 50,
            'parent_id' => $parent->id,
        ]);

        $this->actingAs($this->user)->getJson('/api/v1/cart')->assertOk();

        $this->assertSame(50.0, (float) $child->fresh()->price, 'дочерняя позиция остаётся на обычной цене товара');
        $this->assertNull($child->fresh()->price_old);
    }

    public function test_flag_off_keeps_regular_price_for_any_quantity(): void
    {
        $this->setPriceTiersModule(false);
        $this->seedPriceTiers($this->product, $this->store->id, self::FROM_ONE);

        $added = $this->add(5)->assertOk();
        $this->assertEquals(279.0, (float) $added->json('storeGroups.0.items.0.price'));
        $this->assertNull($added->json('storeGroups.0.items.0.priceOld'));

        $listed = $this->actingAs($this->user)->getJson('/api/v1/cart')->assertOk();
        $this->assertEquals(279.0, (float) $listed->json('storeGroups.0.items.0.price'));
        $this->assertDatabaseHas('cart_items', ['id' => $this->item()->id, 'price' => 279, 'price_old' => null]);
    }
}
