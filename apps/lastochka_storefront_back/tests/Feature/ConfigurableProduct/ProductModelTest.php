<?php

declare(strict_types=1);

namespace Tests\Feature\ConfigurableProduct;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_soft_delete_preserves_variant_in_order_items(): void
    {
        $store = Store::factory()->create();
        $parent = Product::factory()->create([
            'store_id' => $store->id,
            'is_configurable' => true,
            'price' => 1000,
            'quantity' => 0,
        ]);
        $variant = Product::factory()->create([
            'store_id' => $store->id,
            'parent_id' => $parent->id,
            'is_configurable' => false,
            'price' => 1200,
            'quantity' => 5,
        ]);

        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $variant->id,
            'price' => $variant->price,
            'quantity' => 1,
        ]);

        $variant->delete();

        $this->assertSoftDeleted('products', ['id' => $variant->id]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $variant->id,
        ]);

        $withTrashed = Product::withTrashed()->find($variant->id);
        $this->assertNotNull($withTrashed);
        $this->assertNotNull($withTrashed->deleted_at);
    }

    public function test_effective_price_fallback_to_parent_when_variant_null(): void
    {
        $store = Store::factory()->create();
        $parent = Product::factory()->create([
            'store_id' => $store->id,
            'is_configurable' => true,
            'price' => 500,
            'quantity' => 0,
        ]);
        $variant = Product::factory()->create([
            'store_id' => $store->id,
            'parent_id' => $parent->id,
            'is_configurable' => false,
            'price' => null,
            'quantity' => 3,
        ]);

        // getEffectivePrice() объявлен как float — assertSame по int не проходит.
        $this->assertSame(500.0, $variant->getEffectivePrice());
    }

    public function test_effective_price_uses_own_value_when_set(): void
    {
        $store = Store::factory()->create();
        $parent = Product::factory()->create([
            'store_id' => $store->id,
            'is_configurable' => true,
            'price' => 500,
        ]);
        $variant = Product::factory()->create([
            'store_id' => $store->id,
            'parent_id' => $parent->id,
            'price' => 799,
        ]);

        // getEffectivePrice() объявлен как float — assertSame по int не проходит.
        $this->assertSame(799.0, $variant->getEffectivePrice());
    }

    public function test_display_images_fallback_to_parent_when_variant_empty(): void
    {
        $store = Store::factory()->create();
        $parent = Product::factory()->create([
            'store_id' => $store->id,
            'is_configurable' => true,
            'price' => 500,
        ]);
        ProductImage::factory()->count(2)->create([
            'product_id' => $parent->id,
        ]);

        $variant = Product::factory()->create([
            'store_id' => $store->id,
            'parent_id' => $parent->id,
            'price' => 600,
        ]);

        $parent->load('images');
        $variant->setRelation('parent', $parent);

        $images = $variant->display_images;

        $this->assertCount(2, $images);
    }

    public function test_display_images_returns_own_when_variant_has_images(): void
    {
        $store = Store::factory()->create();
        $parent = Product::factory()->create([
            'store_id' => $store->id,
            'is_configurable' => true,
            'price' => 500,
        ]);
        ProductImage::factory()->count(2)->create([
            'product_id' => $parent->id,
        ]);
        $variant = Product::factory()->create([
            'store_id' => $store->id,
            'parent_id' => $parent->id,
            'price' => 600,
        ]);
        ProductImage::factory()->count(3)->create([
            'product_id' => $variant->id,
        ]);

        $variant->load('images');

        $this->assertCount(3, $variant->display_images);
    }

    public function test_get_default_variant_returns_explicit_flag_first(): void
    {
        $store = Store::factory()->create();
        $parent = Product::factory()->create([
            'store_id' => $store->id,
            'is_configurable' => true,
        ]);
        Product::factory()->create([
            'store_id' => $store->id,
            'parent_id' => $parent->id,
            'is_default_variant' => false,
            'is_active' => true,
        ]);
        $explicit = Product::factory()->create([
            'store_id' => $store->id,
            'parent_id' => $parent->id,
            'is_default_variant' => true,
            'is_active' => true,
        ]);
        Product::factory()->create([
            'store_id' => $store->id,
            'parent_id' => $parent->id,
            'is_default_variant' => false,
            'is_active' => true,
        ]);

        $this->assertSame($explicit->id, $parent->fresh()->getDefaultVariant()?->id);
    }

    public function test_get_default_variant_returns_first_by_id_fallback(): void
    {
        $store = Store::factory()->create();
        $parent = Product::factory()->create([
            'store_id' => $store->id,
            'is_configurable' => true,
        ]);
        $first = Product::factory()->create([
            'store_id' => $store->id,
            'parent_id' => $parent->id,
            'is_active' => true,
            'is_default_variant' => false,
        ]);
        Product::factory()->create([
            'store_id' => $store->id,
            'parent_id' => $parent->id,
            'is_active' => true,
            'is_default_variant' => false,
        ]);

        $this->assertSame($first->id, $parent->fresh()->getDefaultVariant()?->id);
    }

    public function test_get_default_variant_returns_null_for_non_configurable(): void
    {
        $store = Store::factory()->create();
        $simple = Product::factory()->create([
            'store_id' => $store->id,
            'is_configurable' => false,
        ]);

        $this->assertNull($simple->getDefaultVariant());
    }
}
