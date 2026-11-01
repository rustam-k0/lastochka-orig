<?php

declare(strict_types=1);

namespace Tests\Feature\Stock;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Остаток и корзина товара «по кг» в одних единицах.
 *
 * Остаток витрины хранится в граммах (products.quantity = 26555 при 26.555 кг в
 * курьерке), а корзина товара measurement_unit=kilogram без кванта — в кг
 * (quantity_step = 1 = 1 кг). Сравнение «как есть» давало: остаток 400 г —
 * товар «в наличии» (400 ≥ 1) и в корзину кладётся до 400 кг. Прод 14.10:
 * 8 таких товаров с остатком 45–990 г продавались по 1 кг.
 *
 * Товар с фасовкой в граммах (quantity_step = 300) уже сравнивался верно —
 * его поведение тест страхует от регресса.
 */
final class KilogramStockUnitsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Store $store;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create([
            'min_order_amount' => null,
            'delivery_price' => 0,
            'free_delivery_from' => null,
            'is_bonus_enabled' => false,
        ]);
        $this->category = Category::factory()->forStore($this->store)->create();
    }

    /** Товар «по кг»: 35 ₽ за кг, остаток в граммах. */
    private function kilogramProduct(int $stockGrams): Product
    {
        return Product::factory()->for($this->store)->inCategory($this->category)->create([
            'price' => 35,
            'quantity' => $stockGrams,
            'quantity_step' => 1,
            'measurement_unit' => 'kilogram',
            'unit_type' => 'kg',
        ]);
    }

    /** Товар с фасовкой: 178.20 ₽ за 300 г, остаток в граммах. */
    private function portionedProduct(int $stockGrams): Product
    {
        return Product::factory()->for($this->store)->inCategory($this->category)->create([
            'price' => 178.20,
            'quantity' => $stockGrams,
            'quantity_step' => 300,
            'measurement_unit' => 'gram',
            'unit_type' => 'kg',
        ]);
    }

    private function addToCart(Product $product, int $quantity)
    {
        return $this->actingAs($this->user)->postJson('/api/v1/cart/items', [
            'productId' => $product->id,
            'quantity' => $quantity,
        ]);
    }

    public function test_kilogram_product_with_less_than_one_kilogram_is_not_in_stock(): void
    {
        $product = $this->kilogramProduct(400);

        $this->assertFalse(Product::query()->whereKey($product->id)->inStock()->exists());
        $this->assertTrue($product->fresh()->minSaleStock() > 400);
    }

    public function test_kilogram_product_with_one_kilogram_is_in_stock(): void
    {
        $product = $this->kilogramProduct(1000);

        $this->assertTrue(Product::query()->whereKey($product->id)->inStock()->exists());
    }

    public function test_cannot_add_more_kilograms_than_in_stock(): void
    {
        $product = $this->kilogramProduct(1500);

        $this->addToCart($product, 1)->assertSuccessful();
        // 1 кг уже в корзине + 1 кг > 1.5 кг остатка
        $this->addToCart($product, 1)->assertStatus(422);
    }

    public function test_cannot_add_one_kilogram_when_stock_is_400_grams(): void
    {
        $this->addToCart($this->kilogramProduct(400), 1)->assertStatus(422);
    }

    public function test_portioned_product_stock_check_is_unchanged(): void
    {
        $product = $this->portionedProduct(600);

        $this->addToCart($product, 600)->assertSuccessful();
        $this->addToCart($product, 300)->assertStatus(422);
    }

    public function test_stock_warning_for_kilogram_product_is_in_kilograms(): void
    {
        $product = $this->kilogramProduct(3000);
        $cart = Cart::factory()->for($this->user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $this->store->id,
            'quantity' => 1,
            'price' => 35,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/cart')->assertOk();

        $this->assertStringContainsString('Осталось только 3 ', (string) $response->json('storeGroups.0.items.0.stockWarning'));
    }
}
