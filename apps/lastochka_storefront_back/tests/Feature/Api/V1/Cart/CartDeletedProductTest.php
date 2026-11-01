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
use Tests\TestCase;

/**
 * Регресс GlitchTip STOREFRONT_BACK-CQ (issue 829):
 * товар в корзине удалён (soft-delete) → GET /cart падал с
 * «Call to a member function getEffectivePrice() on null» в syncPrices().
 */
final class CartDeletedProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_cart_drops_item_whose_product_was_soft_deleted(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => 10,
        ]);

        $cart = Cart::factory()->for($user)->create();
        $item = CartItem::factory()->for($cart)->for($product)->for($store)->create([
            'quantity' => 1,
            'price' => 100,
        ]);

        $product->delete();

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk()
            ->assertJson(['storeGroups' => []]);

        $this->assertDatabaseMissing('cart_items', ['id' => $item->id]);
    }
}
