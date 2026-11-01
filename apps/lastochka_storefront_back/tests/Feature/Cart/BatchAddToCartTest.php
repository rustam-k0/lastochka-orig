<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BatchAddToCartTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Store $store;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->category = Category::factory()->forStore($this->store)->create();
        $this->user = User::factory()->create();
    }

    public function test_batch_add_all_available_products(): void
    {
        $productA = $this->createProduct(price: 1000, quantity: 10);
        $productB = $this->createProduct(price: 500, quantity: 5);

        $response = $this->actingAs($this->user)->postJson('/api/v1/cart/items/batch', [
            'items' => [
                ['productId' => $productA->id, 'quantity' => 1],
                ['productId' => $productB->id, 'quantity' => 2],
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'added' => 2,
                'skipped' => 0,
                'skippedProducts' => [],
            ]);
    }

    public function test_batch_skips_out_of_stock_products(): void
    {
        $available = $this->createProduct(price: 1000, quantity: 10);
        $outOfStock = $this->createProduct(price: 500, quantity: 0);

        $response = $this->actingAs($this->user)->postJson('/api/v1/cart/items/batch', [
            'items' => [
                ['productId' => $available->id, 'quantity' => 1],
                ['productId' => $outOfStock->id, 'quantity' => 1],
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'added' => 1,
                'skipped' => 1,
            ]);

        $skipped = $response->json('skippedProducts');
        $this->assertCount(1, $skipped);
        $this->assertEquals($outOfStock->id, $skipped[0]['id']);
    }

    public function test_batch_returns_422_when_all_items_skipped(): void
    {
        $outOfStock = $this->createProduct(price: 500, quantity: 0);

        $response = $this->actingAs($this->user)->postJson('/api/v1/cart/items/batch', [
            'items' => [
                ['productId' => $outOfStock->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'added' => 0,
                'skipped' => 1,
            ]);
    }

    public function test_batch_validation_requires_items(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/cart/items/batch', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);
    }

    public function test_batch_requires_auth(): void
    {
        $response = $this->postJson('/api/v1/cart/items/batch', [
            'items' => [['productId' => 1, 'quantity' => 1]],
        ]);

        $response->assertUnauthorized();
    }

    private function createProduct(int $price, int $quantity): Product
    {
        return Product::factory()->for($this->store)->inCategory($this->category)->create([
            'price' => $price,
            'quantity' => $quantity,
            'quantity_step' => 1,
            'is_active' => true,
        ]);
    }
}
