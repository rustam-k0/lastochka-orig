<?php

declare(strict_types=1);

namespace Tests\Feature\IgnoreStock;

use App\Actions\Cart\AddToCartAction;
use App\Actions\Order\ReserveProductsAction;
use App\Exceptions\InsufficientStockException;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * В магазине с ignore_stock товар с нулевым остатком должен покупаться:
 * добавляться в корзину и резервироваться без InsufficientStockException.
 */
final class IgnoreStockPurchaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Product::unsetEventDispatcher();
    }

    public function test_add_to_cart_succeeds_for_zero_stock_ignore_stock_store(): void
    {
        $store = Store::factory()->ignoreStock()->create();
        $user = User::factory()->create();
        $product = Product::factory()->for($store)->create([
            'quantity' => 0,
            'quantity_step' => 1,
        ]);

        $cart = app(AddToCartAction::class)->execute($user, $product->id, 3);

        $this->assertDatabaseHas('cart_items', [
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 3,
        ]);
    }

    public function test_add_to_cart_fails_for_zero_stock_normal_store(): void
    {
        $store = Store::factory()->create();
        $user = User::factory()->create();
        $product = Product::factory()->for($store)->create([
            'quantity' => 0,
            'quantity_step' => 1,
        ]);

        $this->expectException(InsufficientStockException::class);

        app(AddToCartAction::class)->execute($user, $product->id, 1);
    }

    public function test_reserve_succeeds_for_zero_stock_ignore_stock_store(): void
    {
        $store = Store::factory()->ignoreStock()->create();
        $product = Product::factory()->for($store)->create([
            'quantity' => 0,
            'quantity_step' => 1,
        ]);

        $item = CartItem::factory()->create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => 5,
        ]);

        app(ReserveProductsAction::class)->execute(collect([$item]));

        // Резерв не списывает остаток (decrement закомментирован) — quantity не уходит в минус.
        $this->assertSame(0, (int) $product->fresh()->quantity);
    }

    public function test_reserve_fails_for_zero_stock_normal_store(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create([
            'quantity' => 0,
            'quantity_step' => 1,
        ]);

        $item = CartItem::factory()->create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => 1,
        ]);

        $this->expectException(InsufficientStockException::class);

        app(ReserveProductsAction::class)->execute(collect([$item]));
    }
}
