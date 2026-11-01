<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Product;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\Supplement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class HasRequiredSupplementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_with_required_supplement_has_flag_true(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => 10,
        ]);

        Supplement::create([
            'title' => 'Соус на выбор',
            'product_id' => $product->id,
            'type' => 'one',
            'is_required' => true,
        ]);

        Supplement::create([
            'title' => 'Допы',
            'product_id' => $product->id,
            'type' => 'many',
            'is_required' => false,
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/products/{$product->id}");

        $response->assertOk()
            ->assertJsonPath('data.hasSupplements', true)
            ->assertJsonPath('data.hasRequiredSupplements', true);
    }

    public function test_product_with_only_optional_supplements_has_required_flag_false(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => 10,
        ]);

        Supplement::create([
            'title' => 'Допы',
            'product_id' => $product->id,
            'type' => 'many',
            'is_required' => false,
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/products/{$product->id}");

        $response->assertOk()
            ->assertJsonPath('data.hasSupplements', true)
            ->assertJsonPath('data.hasRequiredSupplements', false);
    }

    public function test_product_without_supplements_both_flags_false(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => 10,
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/products/{$product->id}");

        $response->assertOk()
            ->assertJsonPath('data.hasSupplements', false)
            ->assertJsonPath('data.hasRequiredSupplements', false);
    }

    public function test_category_products_list_includes_has_required_supplements(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        $productWithRequired = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => 10,
        ]);
        Supplement::create([
            'title' => 'Обязательная',
            'product_id' => $productWithRequired->id,
            'type' => 'one',
            'is_required' => true,
        ]);

        $productOptionalOnly = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => 10,
        ]);
        Supplement::create([
            'title' => 'Необязательная',
            'product_id' => $productOptionalOnly->id,
            'type' => 'many',
            'is_required' => false,
        ]);

        $response = $this->actingAs($user)->getJson(
            "/api/v1/stores/{$store->id}/categories/{$category->id}/products"
        );

        $response->assertOk();

        $items = collect($response->json('data'))->keyBy('id');

        self::assertSame(true, $items[$productWithRequired->id]['hasSupplements']);
        self::assertSame(true, $items[$productWithRequired->id]['hasRequiredSupplements']);
        self::assertSame(true, $items[$productOptionalOnly->id]['hasSupplements']);
        self::assertSame(false, $items[$productOptionalOnly->id]['hasRequiredSupplements']);
    }
}
