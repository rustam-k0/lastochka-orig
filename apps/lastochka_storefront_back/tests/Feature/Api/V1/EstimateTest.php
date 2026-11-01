<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Category;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EstimateTest extends TestCase
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

    // ==================== CRUD ====================

    public function test_list_estimates(): void
    {
        Estimate::factory()->for($this->user)->count(2)->create();
        Estimate::factory()->for(User::factory()->create())->create(); // чужая

        $response = $this->actingAs($this->user)->getJson('/api/v1/estimates');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_create_estimate(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/estimates', [
            'name' => 'Смета для Никольской',
            'description' => 'Квартира на 3 этаже',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Смета для Никольской')
            ->assertJsonPath('data.description', 'Квартира на 3 этаже');

        $this->assertDatabaseHas('estimates', [
            'user_id' => $this->user->id,
            'name' => 'Смета для Никольской',
        ]);
    }

    public function test_create_estimate_validation(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/estimates', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_show_estimate_with_items(): void
    {
        $estimate = Estimate::factory()->for($this->user)->create();
        $product = $this->createProduct(price: 1000);
        EstimateItem::factory()->for($estimate)->for($product)->create(['quantity' => 3]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/estimates/{$estimate->id}");

        $response->assertOk()
            ->assertJsonPath('data.itemsCount', 1)
            ->assertJsonPath('data.totalAmount', 3000);
    }

    public function test_show_estimate_forbidden_for_other_user(): void
    {
        $estimate = Estimate::factory()->for(User::factory()->create())->create();

        $response = $this->actingAs($this->user)->getJson("/api/v1/estimates/{$estimate->id}");

        $response->assertForbidden();
    }

    public function test_update_estimate(): void
    {
        $estimate = Estimate::factory()->for($this->user)->create();

        $response = $this->actingAs($this->user)->putJson("/api/v1/estimates/{$estimate->id}", [
            'name' => 'Новое название',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Новое название');
    }

    public function test_delete_estimate(): void
    {
        $estimate = Estimate::factory()->for($this->user)->create();

        $response = $this->actingAs($this->user)->deleteJson("/api/v1/estimates/{$estimate->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('estimates', ['id' => $estimate->id]);
    }

    // ==================== Items ====================

    public function test_add_item_to_estimate(): void
    {
        $estimate = Estimate::factory()->for($this->user)->create();
        $product = $this->createProduct(price: 500);

        $response = $this->actingAs($this->user)->postJson("/api/v1/estimates/{$estimate->id}/items", [
            'productId' => $product->id,
            'quantity' => 2,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.quantity', 2);

        $this->assertDatabaseHas('estimate_items', [
            'estimate_id' => $estimate->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);
    }

    public function test_add_existing_item_increases_quantity(): void
    {
        $estimate = Estimate::factory()->for($this->user)->create();
        $product = $this->createProduct(price: 500);
        EstimateItem::factory()->for($estimate)->for($product)->create(['quantity' => 3]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/estimates/{$estimate->id}/items", [
            'productId' => $product->id,
            'quantity' => 2,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.quantity', 5);
    }

    public function test_batch_add_items(): void
    {
        $estimate = Estimate::factory()->for($this->user)->create();
        $productA = $this->createProduct(price: 1000);
        $productB = $this->createProduct(price: 500);

        $response = $this->actingAs($this->user)->postJson("/api/v1/estimates/{$estimate->id}/items/batch", [
            'items' => [
                ['productId' => $productA->id, 'quantity' => 1],
                ['productId' => $productB->id, 'quantity' => 2],
            ],
        ]);

        $response->assertOk()
            ->assertJson(['added' => 2]);

        $this->assertEquals(2, $estimate->items()->count());
    }

    public function test_update_item_quantity(): void
    {
        $estimate = Estimate::factory()->for($this->user)->create();
        $product = $this->createProduct(price: 500);
        $item = EstimateItem::factory()->for($estimate)->for($product)->create(['quantity' => 1]);

        $response = $this->actingAs($this->user)->putJson("/api/v1/estimates/{$estimate->id}/items/{$item->id}", [
            'quantity' => 5,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.quantity', 5);
    }

    public function test_remove_item(): void
    {
        $estimate = Estimate::factory()->for($this->user)->create();
        $product = $this->createProduct(price: 500);
        $item = EstimateItem::factory()->for($estimate)->for($product)->create();

        $response = $this->actingAs($this->user)->deleteJson("/api/v1/estimates/{$estimate->id}/items/{$item->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('estimate_items', ['id' => $item->id]);
    }

    // ==================== To Cart ====================

    public function test_to_cart(): void
    {
        $estimate = Estimate::factory()->for($this->user)->create();
        $product = $this->createProduct(price: 500, quantity: 10);
        EstimateItem::factory()->for($estimate)->for($product)->create(['quantity' => 2]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/estimates/{$estimate->id}/to-cart");

        $response->assertOk()
            ->assertJson(['added' => 1, 'skipped' => 0]);
    }

    // ==================== Clone ====================

    public function test_clone_estimate(): void
    {
        $estimate = Estimate::factory()->for($this->user)->create(['name' => 'Оригинал']);
        $product = $this->createProduct(price: 1000);
        EstimateItem::factory()->for($estimate)->for($product)->create(['quantity' => 3]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/estimates/{$estimate->id}/clone");

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Оригинал (копия)')
            ->assertJsonPath('data.itemsCount', 1);

        $this->assertDatabaseCount('estimates', 2);
    }

    // ==================== Share ====================

    public function test_share_estimate(): void
    {
        $estimate = Estimate::factory()->for($this->user)->create();

        $response = $this->actingAs($this->user)->postJson("/api/v1/estimates/{$estimate->id}/share");

        $response->assertOk()
            ->assertJsonStructure(['shareToken', 'shareUrl']);

        $token = $response->json('shareToken');
        $this->assertNotNull($token);

        // shareUrl — пользовательская ссылка (путь из AASA), НЕ API-эндпоинт
        $this->assertEquals(url("/estimates/shared/{$token}"), $response->json('shareUrl'));
        $this->assertStringNotContainsString('/api/v1/', $response->json('shareUrl'));

        // Публичный доступ
        $publicResponse = $this->getJson("/api/v1/estimates/shared/{$token}");
        $publicResponse->assertOk()
            ->assertJsonPath('data.id', $estimate->id);
    }

    public function test_shared_estimate_not_found(): void
    {
        $response = $this->getJson('/api/v1/estimates/shared/nonexistent-token');

        $response->assertNotFound();
    }

    // ==================== Export ====================

    public function test_export_xlsx(): void
    {
        $estimate = Estimate::factory()->for($this->user)->create();
        $product = $this->createProduct(price: 500);
        EstimateItem::factory()->for($estimate)->for($product)->create(['quantity' => 2]);

        $response = $this->actingAs($this->user)->get("/api/v1/estimates/{$estimate->id}/export?format=xlsx");

        $response->assertOk();
        $this->assertEquals(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type')
        );
    }

    public function test_export_pdf(): void
    {
        $estimate = Estimate::factory()->for($this->user)->create();
        $product = $this->createProduct(price: 500);
        EstimateItem::factory()->for($estimate)->for($product)->create(['quantity' => 2]);

        $response = $this->actingAs($this->user)->get("/api/v1/estimates/{$estimate->id}/export?format=pdf");

        $response->assertOk();
        $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_export_invalid_format(): void
    {
        $estimate = Estimate::factory()->for($this->user)->create();

        $response = $this->actingAs($this->user)->getJson("/api/v1/estimates/{$estimate->id}/export?format=csv");

        $response->assertUnprocessable();
    }

    // ==================== Auth ====================

    public function test_requires_auth(): void
    {
        $response = $this->getJson('/api/v1/estimates');

        $response->assertUnauthorized();
    }

    // ==================== Helpers ====================

    private function createProduct(int $price, int $quantity = 100): Product
    {
        return Product::factory()->for($this->store)->inCategory($this->category)->create([
            'price' => $price,
            'quantity' => $quantity,
            'quantity_step' => 1,
            'is_active' => true,
        ]);
    }
}
