<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\B2b;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TogglesB2bModule;
use Tests\TestCase;

final class B2bCartTest extends TestCase
{
    use RefreshDatabase;
    use TogglesB2bModule;

    protected function tearDown(): void
    {
        $this->invalidateB2bModuleCache();
        parent::tearDown();
    }

    public function test_b2b_client_can_add_b2c_product_to_cart(): void
    {
        $this->setB2bModule(true);

        $user = User::factory()->b2b()->create();
        $store = Store::factory()->create(); // B2C
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 500,
            'quantity' => 10,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId' => $product->id,
            'quantity' => 1,
        ]);

        $response->assertOk();
    }

    public function test_b2c_client_cannot_add_b2b_product_to_cart(): void
    {
        $this->setB2bModule(true);

        $user = User::factory()->create(); // B2C
        $store = Store::factory()->b2b()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 500,
            'quantity' => 10,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId' => $product->id,
            'quantity' => 1,
        ]);

        $response->assertForbidden();
    }

    public function test_b2b_client_can_add_b2b_product_to_cart(): void
    {
        $this->setB2bModule(true);

        $user = User::factory()->b2b()->create();
        $store = Store::factory()->b2b()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 500,
            'quantity' => 10,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId' => $product->id,
            'quantity' => 1,
        ]);

        $response->assertOk();
    }

    public function test_module_disabled_b2c_client_can_add_b2b_product_to_cart(): void
    {
        $this->setB2bModule(false);

        $user = User::factory()->create(); // B2C
        $store = Store::factory()->b2b()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 500,
            'quantity' => 10,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId' => $product->id,
            'quantity' => 1,
        ]);

        $response->assertOk();
    }
}
