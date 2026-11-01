<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Сумма сметы — цена порции × число порций. Количество позиции сметы — в
 * единицах корзины: у товара с фасовкой 300 г это граммы (900), и
 * «цена × количество» давало 178.20 × 900 = 160 380 ₽ вместо 534.60.
 */
final class EstimateWeightedTotalTest extends TestCase
{
    use RefreshDatabase;

    public function test_estimate_total_of_portioned_product_counts_portions(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->inCategory(Category::factory()->forStore($store)->create())->create([
            'price' => 178.20,
            'quantity' => 100000,
            'quantity_step' => 300,
            'measurement_unit' => 'gram',
            'unit_type' => 'kg',
        ]);
        $estimate = Estimate::factory()->create(['user_id' => $user->id]);
        EstimateItem::factory()->create(['estimate_id' => $estimate->id, 'product_id' => $product->id, 'quantity' => 900]);

        $total = $this->actingAs($user)->getJson("/api/v1/estimates/{$estimate->id}")->assertOk()->json('data.totalAmount');

        $this->assertSame(534, $total);
    }

    public function test_estimate_total_of_piece_product_is_unchanged(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->inCategory(Category::factory()->forStore($store)->create())->create([
            'price' => 329,
            'quantity' => 100,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
        ]);
        $estimate = Estimate::factory()->create(['user_id' => $user->id]);
        EstimateItem::factory()->create(['estimate_id' => $estimate->id, 'product_id' => $product->id, 'quantity' => 3]);

        $total = $this->actingAs($user)->getJson("/api/v1/estimates/{$estimate->id}")->assertOk()->json('data.totalAmount');

        $this->assertSame(987, $total);
    }
}
