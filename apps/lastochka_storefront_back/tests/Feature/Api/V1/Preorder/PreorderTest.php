<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Preorder;

use App\Models\Category;
use App\Models\Preorder;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PreorderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Store $store;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->store = Store::factory()->create();
        $this->product = Product::factory()->create([
            'store_id' => $this->store->id,
            'quantity' => 0,
        ]);
    }

    private function enablePreorders(): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode(['preorders' => ['label' => 'Заявки', 'enabled' => true]]),
                'type' => 'json',
            ]
        );
        app(ModuleConfigService::class)->invalidateCache();
    }

    // ==================== POST /products/{product}/preorders ====================

    public function test_create_preorder_success(): void
    {
        $this->enablePreorders();

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/products/{$this->product->id}/preorders",
            [
                'fullName' => 'Иван Иванов',
                'phone' => '+79991234567',
                'quantity' => 2,
            ]
        );

        $response->assertCreated()
            ->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.quantity', 2)
            ->assertJsonPath('data.productId', $this->product->id);

        $this->assertDatabaseHas('preorders', [
            'user_id' => $this->user->id,
            'product_id' => $this->product->id,
            'status' => 'new',
        ]);
    }

    public function test_create_preorder_module_disabled_returns_403(): void
    {
        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/products/{$this->product->id}/preorders",
            ['fullName' => 'Test', 'phone' => '+79991234567', 'quantity' => 1]
        );

        $response->assertForbidden();
    }

    public function test_create_preorder_unauthenticated_returns_401(): void
    {
        $this->enablePreorders();

        $response = $this->postJson(
            "/api/v1/products/{$this->product->id}/preorders",
            ['fullName' => 'Test', 'phone' => '+79991234567', 'quantity' => 1]
        );

        $response->assertUnauthorized();
    }

    public function test_create_preorder_validation_error(): void
    {
        $this->enablePreorders();

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/products/{$this->product->id}/preorders",
            []
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['fullName', 'phone', 'quantity']);
    }

    public function test_create_preorder_comment_over_200_chars_rejected(): void
    {
        $this->enablePreorders();

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/products/{$this->product->id}/preorders",
            [
                'fullName' => 'Иван Иванов',
                'phone' => '+79991234567',
                'quantity' => 1,
                'comment' => str_repeat('ы', 201),
            ]
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['comment']);
    }

    public function test_create_preorder_comment_exactly_200_chars_accepted(): void
    {
        $this->enablePreorders();

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/products/{$this->product->id}/preorders",
            [
                'fullName' => 'Иван Иванов',
                'phone' => '+79991234567',
                'quantity' => 1,
                'comment' => str_repeat('ы', 200),
            ]
        );

        $response->assertCreated();
    }

    // ==================== GET /preorders ====================

    public function test_list_preorders_returns_only_own(): void
    {
        $this->enablePreorders();

        Preorder::factory()->create(['user_id' => $this->user->id, 'product_id' => $this->product->id, 'store_id' => $this->store->id]);
        Preorder::factory()->create(['store_id' => $this->store->id]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/preorders');

        $response->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_list_preorders_filter_by_status(): void
    {
        $this->enablePreorders();

        Preorder::factory()->create(['user_id' => $this->user->id, 'product_id' => $this->product->id, 'store_id' => $this->store->id, 'status' => 'new']);
        Preorder::factory()->completed()->create(['user_id' => $this->user->id, 'product_id' => $this->product->id, 'store_id' => $this->store->id]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/preorders?status=new');

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.status', 'new');
    }

    public function test_list_preorders_module_disabled_returns_403(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/preorders');
        $response->assertForbidden();
    }

    // ==================== Product visibility ====================

    public function test_by_category_includes_out_of_stock_when_preorders_enabled(): void
    {
        $this->enablePreorders();
        $category = Category::factory()->forStore($this->store)->create();
        $this->product->categories()->sync([$category->id => ['position' => 0]]);

        Product::factory()->for($this->store)->inCategory($category)->create([
            'quantity' => 10,
        ]);

        $response = $this->getJson("/api/v1/stores/{$this->store->id}/categories/{$category->id}/products");

        $response->assertOk()
            ->assertJsonPath('total', 2);
    }

    public function test_by_category_excludes_out_of_stock_when_preorders_disabled(): void
    {
        $category = Category::factory()->forStore($this->store)->create();
        $this->product->categories()->sync([$category->id => ['position' => 0]]);

        Product::factory()->for($this->store)->inCategory($category)->create([
            'quantity' => 10,
        ]);

        $response = $this->getJson("/api/v1/stores/{$this->store->id}/categories/{$category->id}/products");

        $response->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_show_product_out_of_stock_available_when_preorders_enabled(): void
    {
        $this->enablePreorders();

        $response = $this->getJson("/api/v1/products/{$this->product->id}");

        $response->assertOk()
            ->assertJsonPath('data.isPreorder', true)
            ->assertJsonPath('data.stockQuantity', 0);
    }

    public function test_show_product_out_of_stock_404_when_preorders_disabled(): void
    {
        $response = $this->getJson("/api/v1/products/{$this->product->id}");
        $response->assertNotFound();
    }

    public function test_is_preorder_false_for_in_stock_product(): void
    {
        $this->enablePreorders();
        $inStock = Product::factory()->create(['store_id' => $this->store->id, 'quantity' => 10]);

        $response = $this->getJson("/api/v1/products/{$inStock->id}");

        $response->assertOk()
            ->assertJsonPath('data.isPreorder', false);
    }
}
