<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Product;

use App\Models\Category;
use App\Models\Comparison;
use App\Models\ComparisonItem;
use App\Models\Favorite;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Признак «товар в сравнении» на витрине товаров.
 *
 * Проверки идут через реальный Bearer-токен, а не через actingAs: мобильное
 * приложение ходит на публичные ручки именно с токеном, и подмена резолвера
 * в actingAs обходит HTTP-слой, где и ломалась авторизация.
 */
final class ProductComparisonFlagTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    /**
     * Положить товар в сравнение пользователя.
     */
    private function putInComparison(User $user, Product $product, Category $category): void
    {
        $comparison = Comparison::factory()->for($user)->for($category)->create();
        ComparisonItem::factory()->for($comparison, 'comparison')->for($product)->create();
    }

    public function test_is_in_comparison_true_for_product_in_user_comparison(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create(['quantity' => 10]);

        $this->putInComparison($user, $product, $category);

        $this->withToken($this->token($user))
            ->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.isInComparison', true);
    }

    public function test_is_in_comparison_false_when_product_not_added(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create(['quantity' => 10]);

        $this->withToken($this->token($user))
            ->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.isInComparison', false);
    }

    public function test_is_in_comparison_false_for_guest(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create(['quantity' => 10]);

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.isInComparison', false);
    }

    public function test_is_in_comparison_false_when_product_is_in_another_users_comparison(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create(['quantity' => 10]);

        $this->putInComparison($owner, $product, $category);

        $this->withToken($this->token($stranger))
            ->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.isInComparison', false);
    }

    public function test_is_in_comparison_returned_in_category_listing(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $inComparison = Product::factory()->for($store)->inCategory($category)->create(['quantity' => 10]);
        $plain = Product::factory()->for($store)->inCategory($category)->create(['quantity' => 10]);

        $this->putInComparison($user, $inComparison, $category);

        $response = $this->withToken($this->token($user))
            ->getJson("/api/v1/stores/{$store->id}/categories/{$category->id}/products?perPage=50")
            ->assertOk();

        $flags = collect($response->json('data'))->pluck('isInComparison', 'id');
        $this->assertTrue($flags[$inComparison->id]);
        $this->assertFalse($flags[$plain->id]);
    }

    /**
     * Bearer-токен должен разбираться на публичных ручках товара: без этого
     * и isInComparison, и давно существующий isFavorite всегда false.
     */
    public function test_is_favorite_respects_bearer_token_on_product_card(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create(['quantity' => 10]);

        Favorite::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'created_at' => now(),
        ]);

        $this->withToken($this->token($user))
            ->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.isFavorite', true);
    }

    /**
     * Состав сравнения резолвится одним запросом на HTTP-запрос, поэтому
     * число обращений к comparison_items не зависит от размера выдачи.
     */
    public function test_is_in_comparison_does_not_query_per_product(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $token = $this->token($user);
        $endpoint = "/api/v1/stores/{$store->id}/categories/{$category->id}/products?perPage=100";

        Product::factory()->for($store)->inCategory($category)->create(['quantity' => 10]);
        $withOneProduct = $this->countComparisonQueries($endpoint, $token, 1);

        Product::factory()->count(19)->for($store)->inCategory($category)->create(['quantity' => 10]);
        $withTwentyProducts = $this->countComparisonQueries($endpoint, $token, 20);

        $this->assertSame(
            $withOneProduct,
            $withTwentyProducts,
            "Запросов к comparison_items: {$withOneProduct} на 1 товар против {$withTwentyProducts} на 20",
        );
    }

    private function countComparisonQueries(string $endpoint, string $token, int $expectedProducts): int
    {
        $this->withToken($token)->getJson($endpoint)->assertOk();

        $count = 0;
        DB::listen(function ($query) use (&$count) {
            if (str_contains($query->sql, 'comparison_items')) {
                $count++;
            }
        });

        $this->withToken($token)
            ->getJson($endpoint)
            ->assertOk()
            ->assertJsonCount($expectedProducts, 'data');

        return $count;
    }
}
