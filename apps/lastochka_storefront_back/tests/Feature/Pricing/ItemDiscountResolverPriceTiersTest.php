<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use App\Services\Discount\ItemDiscountResolver;
use App\Services\Pricing\PriceTierSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TogglesPriceTiersModule;
use Tests\TestCase;

final class ItemDiscountResolverPriceTiersTest extends TestCase
{
    use RefreshDatabase;
    use TogglesPriceTiersModule;

    private const FROM_ONE = [
        ['min_quantity' => 1, 'price' => 279],
        ['min_quantity' => 2, 'price' => 249],
        ['min_quantity' => 5, 'price' => 199],
    ];

    private function resolver(): ItemDiscountResolver
    {
        return app(ItemDiscountResolver::class);
    }

    /** Товар в активной акции: по прежним правилам он всегда «со скидкой». */
    private function promotedProduct(): Product
    {
        $product = Product::factory()->create(['price' => 279, 'quantity' => 100, 'quantity_step' => 1]);
        Promotion::factory()->for($product->store)->create(['discount_percent' => 10])
            ->products()->attach($product->id);

        return Product::findOrFail($product->id);
    }

    private function cartItem(Product $product, int $quantity, float $price, ?float $priceOld): CartItem
    {
        $cart = Cart::query()->firstOrCreate(['user_id' => User::factory()->create()->id]);

        return CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $product->store_id,
            'quantity' => $quantity,
            'price' => $price,
            'price_old' => $priceOld,
        ])->load('product');
    }

    private function orderItem(Product $product, int $quantity, float $price, ?float $priceOld, ?array $snapshot): OrderItem
    {
        return OrderItem::factory()->for(Order::factory()->create())->for($product)->create([
            'quantity' => $quantity,
            'price' => $price,
            'price_old' => $priceOld,
            'total' => $price * $quantity,
            'measurement_unit' => 'piece',
            'price_tiers' => $snapshot,
        ])->load('product');
    }

    public function test_cart_item_on_first_tier_is_regular_even_with_active_promotion(): void
    {
        $this->setPriceTiersModule(true);
        $product = $this->promotedProduct();
        $this->seedPriceTiers($product, (int) $product->store_id, self::FROM_ONE);

        $this->assertFalse($this->resolver()->hasDiscount($this->cartItem($product, 1, 279, null)));
    }

    public function test_cart_item_on_cheaper_tier_is_discounted(): void
    {
        $this->setPriceTiersModule(true);
        $product = Product::factory()->create(['price' => 279, 'quantity' => 100, 'quantity_step' => 1]);
        $this->seedPriceTiers($product, (int) $product->store_id, self::FROM_ONE);

        $this->assertTrue($this->resolver()->hasDiscount($this->cartItem($product, 5, 199, 279)));
    }

    public function test_cart_item_below_first_threshold_keeps_legacy_rule(): void
    {
        $this->setPriceTiersModule(true);
        $product = $this->promotedProduct();
        $this->seedPriceTiers($product, (int) $product->store_id, [['min_quantity' => 2, 'price' => 249]]);

        $this->assertTrue(
            $this->resolver()->hasDiscount($this->cartItem($product, 1, 251.1, 279)),
            'ступень не найдена: акция на товаре учитывается как раньше'
        );
    }

    public function test_flag_off_keeps_legacy_rule(): void
    {
        $this->setPriceTiersModule(false);
        $product = $this->promotedProduct();
        $this->seedPriceTiers($product, (int) $product->store_id, self::FROM_ONE);

        $this->assertTrue($this->resolver()->hasDiscount($this->cartItem($product, 1, 251.1, 279)));
    }

    public function test_child_cart_item_is_never_tiered(): void
    {
        $this->setPriceTiersModule(true);
        $product = $this->promotedProduct();
        $this->seedPriceTiers($product, (int) $product->store_id, self::FROM_ONE);

        $parent = $this->cartItem(Product::factory()->create(), 1, 100, null);
        $child = $this->cartItem($product, 1, 279, null);
        $child->update(['parent_id' => $parent->id]);

        $this->assertTrue($this->resolver()->hasDiscount($child->fresh()->load('product')), 'дочерняя позиция: прежнее правило');
    }

    public function test_order_item_uses_its_snapshot_and_quantity(): void
    {
        $this->setPriceTiersModule(true);
        $product = $this->promotedProduct();
        $snapshot = PriceTierSnapshot::build(251.1, 279.0, false, self::FROM_ONE)->toArray();

        $this->assertFalse(
            $this->resolver()->hasDiscount($this->orderItem($product, 1, 279, null, $snapshot)),
            'первая ступень: позиция обычная, акция на товаре не учитывается'
        );
        $this->assertTrue($this->resolver()->hasDiscount($this->orderItem($product, 5, 199, 279, $snapshot)));
    }

    public function test_order_item_without_snapshot_keeps_legacy_rule(): void
    {
        $this->setPriceTiersModule(true);
        $product = $this->promotedProduct();

        $this->assertTrue($this->resolver()->hasDiscount($this->orderItem($product, 1, 251.1, null, null)));
    }

    /**
     * Позиция заказа зависит только от наличия снапшота: заказ, оформленный при
     * включённом модуле, досчитывается по снапшоту и после выключения флага.
     */
    public function test_order_item_snapshot_is_honoured_when_flag_is_off(): void
    {
        $this->setPriceTiersModule(false);
        $product = $this->promotedProduct();
        $snapshot = PriceTierSnapshot::build(251.1, 279.0, false, self::FROM_ONE)->toArray();

        $this->assertFalse($this->resolver()->hasDiscount($this->orderItem($product, 1, 279, null, $snapshot)));
        $this->assertTrue($this->resolver()->hasDiscount($this->orderItem($product, 5, 199, 279, $snapshot)));
    }
}
