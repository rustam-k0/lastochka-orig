<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Объединение стоп-листов с полями карточки (Platrum 12188): скрытие с витрины
 * определяет ТОЛЬКО is_active, «только самовывоз» — ТОЛЬКО pickup_only.
 * Флаги stop_list_* удалены.
 */
final class ProductInactiveVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_pickup_only_reflects_pickup_only_field(): void
    {
        $regular = Product::factory()->create(['pickup_only' => false]);
        $pickupOnly = Product::factory()->create(['pickup_only' => true]);

        $this->assertFalse($regular->isPickupOnly());
        $this->assertTrue($pickupOnly->isPickupOnly());
    }

    public function test_scope_active_hides_inactive_product(): void
    {
        $store = Store::factory()->create();

        $visible = Product::factory()->for($store)->create(['is_active' => true]);
        $inactive = Product::factory()->for($store)->create(['is_active' => false]);

        $activeIds = Product::query()->active()->pluck('id')->all();

        $this->assertContains($visible->id, $activeIds);
        $this->assertNotContains($inactive->id, $activeIds);
    }

    public function test_scope_purchasable_excludes_inactive_product(): void
    {
        $store = Store::factory()->create();

        $visible = Product::factory()->for($store)->create([
            'is_active' => true,
            'price' => 100,
            'quantity' => 10,
            'quantity_step' => 1,
        ]);
        $inactive = Product::factory()->for($store)->create([
            'is_active' => false,
            'price' => 100,
            'quantity' => 10,
            'quantity_step' => 1,
        ]);

        $purchasableIds = Product::query()->purchasable()->pluck('id')->all();

        $this->assertContains($visible->id, $purchasableIds);
        $this->assertNotContains($inactive->id, $purchasableIds);
    }

    public function test_product_detail_404_for_inactive_product(): void
    {
        $store = Store::factory()->create();

        $inactive = Product::factory()->for($store)->create([
            'is_active' => false,
            'price' => 100,
            'quantity' => 10,
            'quantity_step' => 1,
        ]);

        $this->getJson("/api/v1/products/{$inactive->id}")->assertNotFound();
    }

    public function test_product_detail_available_for_active_product(): void
    {
        $store = Store::factory()->create();

        $visible = Product::factory()->for($store)->create([
            'is_active' => true,
            'price' => 100,
            'quantity' => 10,
            'quantity_step' => 1,
        ]);

        $this->getJson("/api/v1/products/{$visible->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $visible->id);
    }

    public function test_category_listing_excludes_inactive_product(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->create();

        $visible = Product::factory()->for($store)->create([
            'is_active' => true,
            'price' => 100,
            'quantity' => 10,
            'quantity_step' => 1,
        ]);
        $inactive = Product::factory()->for($store)->create([
            'is_active' => false,
            'price' => 100,
            'quantity' => 10,
            'quantity_step' => 1,
        ]);

        $visible->categories()->attach($category->id, ['position' => 0]);
        $inactive->categories()->attach($category->id, ['position' => 1]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories/{$category->id}/products");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }
}
