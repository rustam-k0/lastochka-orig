<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Cart\AvailableShippingMethodsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AvailableShippingMethodsPickupOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_courier_cdek_russian_post_unavailable_when_cart_has_pickup_only_product(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'pickup_only' => true,
        ]);

        $user = User::factory()->create();
        $cart = Cart::factory()->create(['user_id' => $user->id]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'store_id' => $store->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 500,
        ]);

        $methods = app(AvailableShippingMethodsResolver::class)->resolve($cart->fresh());
        $byMethod = collect($methods)->keyBy('method');

        $this->assertFalse($byMethod['own_courier']['available']);
        $this->assertNotNull($byMethod['own_courier']['reason']);
        $this->assertFalse($byMethod['cdek']['available']);
        $this->assertFalse($byMethod['russian_post']['available']);
        $this->assertTrue($byMethod['pickup']['available']);
    }

    public function test_all_methods_default_available_without_pickup_only_product(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'pickup_only' => false,
        ]);

        $user = User::factory()->create();
        $cart = Cart::factory()->create(['user_id' => $user->id]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'store_id' => $store->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 500,
        ]);

        $methods = app(AvailableShippingMethodsResolver::class)->resolve($cart->fresh());
        $byMethod = collect($methods)->keyBy('method');

        $this->assertTrue($byMethod['own_courier']['available']);
        $this->assertNull($byMethod['own_courier']['reason']);
        $this->assertTrue($byMethod['pickup']['available']);
    }

    public function test_empty_cart_has_no_pickup_only_restriction(): void
    {
        $methods = app(AvailableShippingMethodsResolver::class)->resolve(null);
        $byMethod = collect($methods)->keyBy('method');

        $this->assertTrue($byMethod['own_courier']['available']);
        $this->assertTrue($byMethod['pickup']['available']);
    }

    public function test_courier_unavailable_when_items_relation_preloaded_without_product(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'pickup_only' => true,
        ]);

        $user = User::factory()->create();
        $cart = Cart::factory()->create(['user_id' => $user->id]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'store_id' => $store->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 500,
        ]);

        // Mirrors the real CartController call site: `items` relation IS preloaded,
        // but `items.product` is NOT — this is exactly the branch the N+1 fix targets.
        $preloadedCart = Cart::with('items')->find($cart->id);

        $methods = app(AvailableShippingMethodsResolver::class)->resolve($preloadedCart);
        $byMethod = collect($methods)->keyBy('method');

        $this->assertFalse($byMethod['own_courier']['available']);
        $this->assertTrue($byMethod['pickup']['available']);
    }
}
