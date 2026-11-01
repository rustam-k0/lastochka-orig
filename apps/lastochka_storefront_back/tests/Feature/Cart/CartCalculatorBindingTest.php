<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\DTOs\Cart\CartStoreGroupData;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\Promocode;
use App\Models\Store;
use App\Models\User;
use App\Services\Cart\CartCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CartCalculatorBindingTest extends TestCase
{
    use RefreshDatabase;

    private CartCalculator $calculator;

    private Store $store;

    private Category $category;

    private Cart $cart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new CartCalculator;
        $this->store = Store::factory()->create();
        $this->category = Category::factory()->forStore($this->store)->create();
        $this->cart = Cart::factory()->for(User::factory()->create())->create();
    }

    // ==================== binding_type=all ====================

    public function test_binding_type_all_applies_discount_to_all_eligible_items(): void
    {
        $productA = $this->createProduct(price: 1000);
        $productB = $this->createProduct(price: 500);

        $itemA = $this->createCartItem($productA, quantity: 1);
        $itemB = $this->createCartItem($productB, quantity: 2);

        $promocode = Promocode::factory()->percent(10)->create([
            'store_id' => $this->store->id,
            'binding_type' => 'all',
        ]);

        $group = $this->buildGroup([$itemA, $itemB], $promocode);

        // 10% от (1000*1 + 500*2) = 10% от 2000 = 200
        $this->assertEquals(200, $this->calculator->calculateDiscount($group));
    }

    // ==================== binding_type=products ====================

    public function test_binding_type_products_applies_discount_only_to_bound_products(): void
    {
        $productA = $this->createProduct(price: 1000);
        $productB = $this->createProduct(price: 500);

        $itemA = $this->createCartItem($productA, quantity: 1);
        $itemB = $this->createCartItem($productB, quantity: 2);

        $promocode = Promocode::factory()->percent(10)->create([
            'store_id' => $this->store->id,
            'binding_type' => 'products',
        ]);
        $promocode->products()->attach($productA->id);

        $group = $this->buildGroup([$itemA, $itemB], $promocode);

        // 10% только от productA: 10% от 1000 = 100
        $this->assertEquals(100, $this->calculator->calculateDiscount($group));
    }

    // ==================== binding_type=categories ====================

    public function test_binding_type_categories_applies_discount_only_to_products_in_bound_categories(): void
    {
        $categoryA = Category::factory()->forStore($this->store)->create();
        $categoryB = Category::factory()->forStore($this->store)->create();

        $productA = $this->createProduct(price: 1000, category: $categoryA);
        $productB = $this->createProduct(price: 500, category: $categoryB);

        $itemA = $this->createCartItem($productA, quantity: 1);
        $itemB = $this->createCartItem($productB, quantity: 2);

        $promocode = Promocode::factory()->percent(20)->create([
            'store_id' => $this->store->id,
            'binding_type' => 'categories',
        ]);
        $promocode->categories()->attach($categoryA->id);

        $group = $this->buildGroup([$itemA, $itemB], $promocode);

        // 20% только от categoryA товаров: 20% от 1000 = 200
        $this->assertEquals(200, $this->calculator->calculateDiscount($group));
    }

    // ==================== Fixed + binding_type=products ====================

    public function test_fixed_discount_capped_at_eligible_bound_products_total(): void
    {
        $productA = $this->createProduct(price: 300);
        $productB = $this->createProduct(price: 500);

        $itemA = $this->createCartItem($productA, quantity: 1);
        $itemB = $this->createCartItem($productB, quantity: 1);

        $promocode = Promocode::factory()->fixed(1000)->create([
            'store_id' => $this->store->id,
            'binding_type' => 'products',
        ]);
        $promocode->products()->attach($productA->id);

        $group = $this->buildGroup([$itemA, $itemB], $promocode);

        // fixed 1000, но eligible только productA (300), cap = min(1000, 300) = 300
        $this->assertEquals(300, $this->calculator->calculateDiscount($group));
    }

    // ==================== Binding + hasDiscount ====================

    public function test_items_with_discount_excluded_even_if_in_binding(): void
    {
        $productA = $this->createProduct(price: 800, priceOld: 1000);
        $productB = $this->createProduct(price: 500);

        $itemA = $this->createCartItem($productA, quantity: 1);
        $itemB = $this->createCartItem($productB, quantity: 1);

        $promocode = Promocode::factory()->percent(10)->create([
            'store_id' => $this->store->id,
            'binding_type' => 'products',
        ]);
        // Оба товара привязаны, но productA имеет скидку (price_old)
        $promocode->products()->attach([$productA->id, $productB->id]);

        $group = $this->buildGroup([$itemA, $itemB], $promocode);

        // productA исключён (hasDiscount), только productB: 10% от 500 = 50
        $this->assertEquals(50, $this->calculator->calculateDiscount($group));
    }

    // ==================== Helpers ====================

    private function createProduct(int $price, ?int $priceOld = null, ?Category $category = null): Product
    {
        return Product::factory()->for($this->store)->inCategory($category ?? $this->category)->create([
            'price' => $price,
            'price_old' => $priceOld,
            'quantity' => 100,
            'quantity_step' => 1,
        ]);
    }

    private function createCartItem(Product $product, int $quantity): CartItem
    {
        return CartItem::factory()->for($this->cart)->for($product)->for($this->store)->create([
            'quantity' => $quantity,
            'price' => $product->price,
        ]);
    }

    /**
     * @param  CartItem[]  $items
     */
    private function buildGroup(array $items, ?Promocode $promocode = null): CartStoreGroupData
    {
        $cartItems = collect($items)->each(fn (CartItem $item) => $item->load('product'));

        if ($promocode) {
            $promocode->load(['products', 'categories']);
        }

        return new CartStoreGroupData(
            store: $this->store,
            items: $cartItems,
            promocode: $promocode,
        );
    }
}
