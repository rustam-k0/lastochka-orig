<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Favorite;

use App\Models\Favorite;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class FavoriteTest extends TestCase
{
    use RefreshDatabase;

    // ==================== Get Favorites ====================

    public function test_get_favorites(): void
    {
        $user = User::factory()->create();
        $product1 = Product::factory()->create(['title' => 'Молоко']);
        $product2 = Product::factory()->create(['title' => 'Хлеб']);

        Favorite::factory()->for($user)->for($product1)->create();
        Favorite::factory()->for($user)->for($product2)->create();

        $response = $this->actingAs($user)->getJson('/api/v1/favorites');

        $response->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonCount(2, 'data');
    }

    public function test_get_favorites_empty(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/favorites');

        $response->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('hasMore', false)
            ->assertJsonCount(0, 'data');
    }

    public function test_get_favorites_requires_auth(): void
    {
        $response = $this->getJson('/api/v1/favorites');

        $response->assertUnauthorized();
    }

    public function test_get_favorites_returns_products_with_is_favorite_true(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        Favorite::factory()->for($user)->for($product)->create();

        $response = $this->actingAs($user)->getJson('/api/v1/favorites');

        $response->assertOk()
            ->assertJsonPath('data.0.isFavorite', true);
    }

    public function test_get_favorites_does_not_return_other_users_favorites(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $product = Product::factory()->create();

        Favorite::factory()->for($otherUser)->for($product)->create();

        $response = $this->actingAs($user)->getJson('/api/v1/favorites');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_get_favorites_excludes_inactive_products(): void
    {
        $user = User::factory()->create();
        $activeProduct = Product::factory()->create();
        $inactiveProduct = Product::factory()->inactive()->create();

        Favorite::factory()->for($user)->for($activeProduct)->create();
        Favorite::factory()->for($user)->for($inactiveProduct)->create();

        $response = $this->actingAs($user)->getJson('/api/v1/favorites');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $activeProduct->id);
    }

    public function test_get_favorites_sorted_by_created_at_desc(): void
    {
        $user = User::factory()->create();
        $product1 = Product::factory()->create(['title' => 'Первый']);
        $product2 = Product::factory()->create(['title' => 'Второй']);

        // Создаём первый, потом второй
        Favorite::factory()->for($user)->for($product1)->create([
            'created_at' => now()->subDay(),
        ]);
        Favorite::factory()->for($user)->for($product2)->create([
            'created_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/favorites');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $product2->id)
            ->assertJsonPath('data.1.id', $product1->id);
    }

    public function test_get_favorites_sorted_date_added_asc(): void
    {
        $user = User::factory()->create();
        $product1 = Product::factory()->create(['title' => 'Первый']);
        $product2 = Product::factory()->create(['title' => 'Второй']);

        Favorite::factory()->for($user)->for($product1)->create([
            'created_at' => now()->subDay(),
        ]);
        Favorite::factory()->for($user)->for($product2)->create([
            'created_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/favorites?sort=date_added_asc');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $product1->id)
            ->assertJsonPath('data.1.id', $product2->id);
    }

    public function test_get_favorites_sorted_date_added_desc(): void
    {
        $user = User::factory()->create();
        $product1 = Product::factory()->create(['title' => 'Первый']);
        $product2 = Product::factory()->create(['title' => 'Второй']);

        Favorite::factory()->for($user)->for($product1)->create([
            'created_at' => now()->subDay(),
        ]);
        Favorite::factory()->for($user)->for($product2)->create([
            'created_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/favorites?sort=date_added_desc');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $product2->id)
            ->assertJsonPath('data.1.id', $product1->id);
    }

    public function test_get_favorites_unknown_sort_falls_back_to_desc(): void
    {
        $user = User::factory()->create();
        $product1 = Product::factory()->create(['title' => 'Первый']);
        $product2 = Product::factory()->create(['title' => 'Второй']);

        Favorite::factory()->for($user)->for($product1)->create([
            'created_at' => now()->subDay(),
        ]);
        Favorite::factory()->for($user)->for($product2)->create([
            'created_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/favorites?sort=price_asc');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $product2->id)
            ->assertJsonPath('data.1.id', $product1->id);
    }

    public function test_get_favorites_equal_created_at_ordered_by_id(): void
    {
        $user = User::factory()->create();
        $product1 = Product::factory()->create(['title' => 'Первый']);
        $product2 = Product::factory()->create(['title' => 'Второй']);

        // Одинаковый created_at — порядок определяет tie-breaker по id
        $createdAt = now();
        Favorite::factory()->for($user)->for($product1)->create(['created_at' => $createdAt]);
        Favorite::factory()->for($user)->for($product2)->create(['created_at' => $createdAt]);

        $response = $this->actingAs($user)->getJson('/api/v1/favorites?sort=date_added_asc');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $product1->id)
            ->assertJsonPath('data.1.id', $product2->id);

        $response = $this->actingAs($user)->getJson('/api/v1/favorites?sort=date_added_desc');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $product2->id)
            ->assertJsonPath('data.1.id', $product1->id);
    }

    public function test_get_favorites_pagination(): void
    {
        $user = User::factory()->create();

        // Создаём 25 избранных товаров
        for ($i = 0; $i < 25; $i++) {
            $product = Product::factory()->create();
            Favorite::factory()->for($user)->for($product)->create();
        }

        $response = $this->actingAs($user)->getJson('/api/v1/favorites?perPage=10');

        $response->assertOk()
            ->assertJsonPath('hasMore', true)
            ->assertJsonPath('total', 25)
            ->assertJsonCount(10, 'data');

        // Вторая страница
        $response = $this->actingAs($user)->getJson('/api/v1/favorites?perPage=10&page=2');

        $response->assertOk()
            ->assertJsonPath('hasMore', true)
            ->assertJsonCount(10, 'data');

        // Третья страница
        $response = $this->actingAs($user)->getJson('/api/v1/favorites?perPage=10&page=3');

        $response->assertOk()
            ->assertJsonPath('hasMore', false)
            ->assertJsonCount(5, 'data');
    }

    // ==================== Toggle Favorite ====================

    public function test_toggle_favorite_add(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['title' => 'Молоко']);

        $response = $this->actingAs($user)->postJson('/api/v1/favorites/toggle', [
            'productId' => $product->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.isFavorite', true);

        $this->assertDatabaseHas('favorites', [
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_toggle_favorite_remove(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        Favorite::factory()->for($user)->for($product)->create();

        $response = $this->actingAs($user)->postJson('/api/v1/favorites/toggle', [
            'productId' => $product->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.isFavorite', false);

        $this->assertDatabaseMissing('favorites', [
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_toggle_favorite_add_when_concurrent_request_already_added(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        // Параллельный toggle (двойной тап) успевает вставить строку между
        // проверкой «записи нет» и нашим INSERT.
        $raced = false;
        DB::listen(function (QueryExecuted $query) use (&$raced, $user, $product): void {
            if ($raced || ! str_starts_with($query->sql, 'select * from "favorites"')) {
                return;
            }
            $raced = true;
            DB::table('favorites')->insert([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'created_at' => now(),
            ]);
        });

        $response = $this->actingAs($user)->postJson('/api/v1/favorites/toggle', [
            'productId' => $product->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.isFavorite', true);

        $this->assertSame(1, Favorite::where('user_id', $user->id)->where('product_id', $product->id)->count());
    }

    public function test_toggle_favorite_invalid_product(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/favorites/toggle', [
            'productId' => 99999,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['productId']);
    }

    public function test_toggle_favorite_requires_auth(): void
    {
        $product = Product::factory()->create();

        $response = $this->postJson('/api/v1/favorites/toggle', [
            'productId' => $product->id,
        ]);

        $response->assertUnauthorized();
    }

    public function test_toggle_favorite_requires_product_id(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/favorites/toggle', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['productId']);
    }

    public function test_toggle_favorite_limit(): void
    {
        $user = User::factory()->create();

        // Создаём 500 избранных
        for ($i = 0; $i < 500; $i++) {
            $product = Product::factory()->create();
            Favorite::factory()->for($user)->for($product)->create();
        }

        // Пытаемся добавить 501-й
        $newProduct = Product::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/favorites/toggle', [
            'productId' => $newProduct->id,
        ]);

        $response->assertUnprocessable()
            ->assertJsonFragment(['message' => 'Достигнут лимит избранного (максимум 500 товаров).']);

        $this->assertDatabaseMissing('favorites', [
            'user_id' => $user->id,
            'product_id' => $newProduct->id,
        ]);
    }

    public function test_toggle_favorite_can_remove_at_limit(): void
    {
        $user = User::factory()->create();

        // Создаём 500 избранных
        $products = [];
        for ($i = 0; $i < 500; $i++) {
            $product = Product::factory()->create();
            Favorite::factory()->for($user)->for($product)->create();
            $products[] = $product;
        }

        // Удаление работает даже при лимите
        $response = $this->actingAs($user)->postJson('/api/v1/favorites/toggle', [
            'productId' => $products[0]->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.isFavorite', false);

        $this->assertDatabaseMissing('favorites', [
            'user_id' => $user->id,
            'product_id' => $products[0]->id,
        ]);
    }

    // ==================== Batch Remove Favorites ====================

    public function test_batch_remove_favorites(): void
    {
        $user = User::factory()->create();
        $product1 = Product::factory()->create();
        $product2 = Product::factory()->create();
        $product3 = Product::factory()->create();

        Favorite::factory()->for($user)->for($product1)->create();
        Favorite::factory()->for($user)->for($product2)->create();
        Favorite::factory()->for($user)->for($product3)->create();

        $response = $this->actingAs($user)->deleteJson('/api/v1/favorites', [
            'productIds' => [$product1->id, $product2->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('removed', 2);

        $this->assertDatabaseMissing('favorites', ['user_id' => $user->id, 'product_id' => $product1->id]);
        $this->assertDatabaseMissing('favorites', ['user_id' => $user->id, 'product_id' => $product2->id]);
        $this->assertDatabaseHas('favorites', ['user_id' => $user->id, 'product_id' => $product3->id]);
    }

    public function test_batch_remove_ignores_ids_not_in_favorites(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        Favorite::factory()->for($user)->for($product)->create();

        $response = $this->actingAs($user)->deleteJson('/api/v1/favorites', [
            'productIds' => [$product->id, 99999],
        ]);

        $response->assertOk()
            ->assertJsonPath('removed', 1);

        // Повторный вызов идемпотентен
        $response = $this->actingAs($user)->deleteJson('/api/v1/favorites', [
            'productIds' => [$product->id, 99999],
        ]);

        $response->assertOk()
            ->assertJsonPath('removed', 0);
    }

    public function test_batch_remove_does_not_touch_other_users_favorites(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $product = Product::factory()->create();

        Favorite::factory()->for($user)->for($product)->create();
        Favorite::factory()->for($otherUser)->for($product)->create();

        $response = $this->actingAs($user)->deleteJson('/api/v1/favorites', [
            'productIds' => [$product->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('removed', 1);

        $this->assertDatabaseMissing('favorites', ['user_id' => $user->id, 'product_id' => $product->id]);
        $this->assertDatabaseHas('favorites', ['user_id' => $otherUser->id, 'product_id' => $product->id]);
    }

    public function test_batch_remove_requires_product_ids(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->deleteJson('/api/v1/favorites', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['productIds']);
    }

    public function test_batch_remove_rejects_empty_array(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->deleteJson('/api/v1/favorites', [
            'productIds' => [],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['productIds']);
    }

    public function test_batch_remove_rejects_non_integer_ids(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->deleteJson('/api/v1/favorites', [
            'productIds' => ['abc'],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['productIds.0']);
    }

    public function test_batch_remove_requires_auth(): void
    {
        $response = $this->deleteJson('/api/v1/favorites', [
            'productIds' => [1],
        ]);

        $response->assertUnauthorized();
    }

    // ==================== isFavorite Flag in Product List ====================

    public function test_is_favorite_flag_in_product_list(): void
    {
        $user = User::factory()->create();
        $favoriteProduct = Product::factory()->create(['title' => 'Избранный']);
        $regularProduct = Product::factory()->create(['title' => 'Обычный']);

        Favorite::factory()->for($user)->for($favoriteProduct)->create();

        // Проверяем через отдельный запрос товара
        $response = $this->actingAs($user)->getJson("/api/v1/products/{$favoriteProduct->id}");
        $response->assertOk()->assertJsonPath('data.isFavorite', true);

        $response = $this->actingAs($user)->getJson("/api/v1/products/{$regularProduct->id}");
        $response->assertOk()->assertJsonPath('data.isFavorite', false);
    }
}
