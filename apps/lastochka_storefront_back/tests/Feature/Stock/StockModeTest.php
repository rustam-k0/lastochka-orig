<?php

namespace Tests\Feature\Stock;

use App\Actions\Order\CancelOrderAction;
use App\Actions\Order\ReserveProductsAction;
use App\Enums\OrderStatus;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\RabbitMQ\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockModeTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        return Store::factory()->create(['ignore_stock' => false]);
    }

    private function product(array $attrs = []): Product
    {
        return Product::factory()->create(array_merge([
            'store_id' => $this->store()->id,
            'quantity' => 0, 'quantity_step' => 1, 'is_active' => true, 'price' => 100,
        ], $attrs));
    }

    public function test_ignore_product_purchasable_at_zero(): void
    {
        $this->product(['stock_mode' => 'ignore', 'quantity' => 0]);
        $this->assertSame(1, Product::purchasable()->count());
    }

    public function test_ignore_product_is_stock_ignored(): void
    {
        $p = $this->product(['stock_mode' => 'ignore']);
        $this->assertTrue($p->isStockIgnored());
    }

    public function test_fixed_product_unavailable_at_zero(): void
    {
        $this->product(['stock_mode' => 'fixed', 'fixed_stock' => 100, 'quantity' => 0]);
        $this->assertSame(0, Product::inStock()->count());
    }

    public function test_fixed_product_available_with_quantity(): void
    {
        $this->product(['stock_mode' => 'fixed', 'fixed_stock' => 100, 'quantity' => 50]);
        $this->assertSame(1, Product::inStock()->count());
    }

    public function test_stock_updated_ignored_for_fixed_product(): void
    {
        $product = $this->product([
            'stock_mode' => 'fixed', 'fixed_stock' => 100, 'quantity' => 40,
            'unit_type' => 'piece', 'quantity_step' => 1,
        ]);

        app(ProductEventTransformer::class)->processStockUpdatedEvent($product, [
            'event_id' => Str::uuid()->toString(),
            'quantity' => 999,
        ]);

        $this->assertSame(40, $product->fresh()->quantity); // 1С-остаток проигнорирован
    }

    public function test_stock_updated_applies_for_normal_product(): void
    {
        $product = $this->product([
            'stock_mode' => 'normal', 'quantity' => 10,
            'unit_type' => 'piece', 'quantity_step' => 1,
        ]);

        app(ProductEventTransformer::class)->processStockUpdatedEvent($product, [
            'event_id' => Str::uuid()->toString(),
            'quantity' => 77,
        ]);

        $this->assertSame(77, $product->fresh()->quantity);
    }

    public function test_fixed_decrements_on_reserve(): void
    {
        $product = $this->product(['stock_mode' => 'fixed', 'fixed_stock' => 100, 'quantity' => 100]);

        $cartItem = new CartItem(['product_id' => $product->id, 'quantity' => 3]);
        $cartItem->setRelation('product', $product);

        app(ReserveProductsAction::class)->execute(collect([$cartItem]));

        $this->assertSame(97, $product->fresh()->quantity);
    }

    public function test_normal_does_not_decrement_on_reserve(): void
    {
        $product = $this->product(['stock_mode' => 'normal', 'quantity' => 100]);

        $cartItem = new CartItem(['product_id' => $product->id, 'quantity' => 3]);
        $cartItem->setRelation('product', $product);

        app(ReserveProductsAction::class)->execute(collect([$cartItem]));

        $this->assertSame(100, $product->fresh()->quantity);
    }

    public function test_fixed_returns_on_cancel(): void
    {
        $store = $this->store();
        $product = $this->product([
            'store_id' => $store->id,
            'stock_mode' => 'fixed', 'fixed_stock' => 100, 'quantity' => 97,
        ]);

        $user = User::factory()->create();
        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Assembling,
            'payment_type' => \App\Enums\PaymentType::Cash,
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'title' => $product->title,
            'sku' => 'SKU-FIXED-CANCEL',
            'price' => 100,
            'quantity' => 3,
            'total' => 300,
        ]);

        app(CancelOrderAction::class)->execute($order);

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(100, $product->fresh()->quantity);
    }

    public function test_reset_fixed_sets_quantity_to_limit(): void
    {
        $fixed = $this->product(['stock_mode' => 'fixed', 'fixed_stock' => 100, 'quantity' => 12]);
        $normal = $this->product(['stock_mode' => 'normal', 'quantity' => 5]);

        $this->artisan('stock:reset-fixed')->assertExitCode(0);

        $this->assertSame(100, $fixed->fresh()->quantity);
        $this->assertSame(5, $normal->fresh()->quantity); // normal не тронут
    }
}
