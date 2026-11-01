<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Models\Product;
use App\Models\Promotion;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Product::getOriginalPrice() — зачёркнутая оригинальная цена ДО скидки.
 * Возвращает базовую цену при наличии скидки (ручной/акционной), иначе null.
 */
final class ProductOriginalPriceTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_null_when_no_discount(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create([
            'price' => 300,
            'price_old' => null,
        ]);

        $this->assertNull($product->getOriginalPrice());
    }

    public function test_returns_price_old_for_manual_discount(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create([
            'price' => 240,
            'price_old' => 300,
        ]);

        $this->assertSame(240.0, $product->getEffectivePrice());
        $this->assertSame(300.0, $product->getOriginalPrice());
    }

    public function test_returns_base_price_for_promo_discount(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create([
            'price' => 300,
            'price_old' => null,
        ]);

        $promotion = Promotion::factory()->for($store)->withDiscountPercent(20)->create();
        $promotion->products()->attach($product);

        $this->assertSame(240.0, $product->getEffectivePrice());
        $this->assertSame(300.0, $product->getOriginalPrice());
    }

    public function test_returns_base_price_when_promo_beats_manual_discount(): void
    {
        $store = Store::factory()->create();
        // Ручная скидка 30 (300→270), акция 20% от базы 300 = 60 → побеждает акция.
        $product = Product::factory()->for($store)->create([
            'price' => 270,
            'price_old' => 300,
        ]);

        $promotion = Promotion::factory()->for($store)->withDiscountPercent(20)->create();
        $promotion->products()->attach($product);

        $this->assertSame(240.0, $product->getEffectivePrice());
        $this->assertSame(300.0, $product->getOriginalPrice());
    }

    public function test_variant_inherits_parent_discount(): void
    {
        $store = Store::factory()->create();
        $parent = Product::factory()->for($store)->create([
            'is_configurable' => true,
            'price' => 300,
            'price_old' => 400,
        ]);
        $variant = Product::factory()->for($store)->create([
            'parent_id' => $parent->id,
            'is_configurable' => false,
            'price' => null,
            'price_old' => null,
        ]);

        $this->assertSame(300.0, $variant->getEffectivePrice());
        $this->assertSame(400.0, $variant->getOriginalPrice());
    }
}
