<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Review;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_product_reviews(): void
    {
        // Arrange
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create(['rating' => 4.0]);
        ProductReview::factory()
            ->for($product)
            ->for($store)
            ->published()
            ->count(3)
            ->create(['rating' => 5]);
        ProductReview::factory()
            ->for($product)
            ->for($store)
            ->published()
            ->count(2)
            ->create(['rating' => 3]);
        // Неопубликованный отзыв — не должен попасть в список
        ProductReview::factory()->for($product)->for($store)->create(['is_published' => false]);

        // Act
        $response = $this->getJson("/api/v1/products/{$product->id}/reviews");

        // Assert
        $response->assertOk()
            ->assertJsonStructure([
                'hasMore',
                'data' => [
                    '*' => ['id', 'rating', 'description', 'authorName', 'isPublished', 'createdAt'],
                ],
                'total',
                'averageRating',
                'ratingsCount' => ['5', '4', '3', '2', '1'],
            ])
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('total', 5)
            ->assertJsonPath('ratingsCount.5', 3)
            ->assertJsonPath('ratingsCount.3', 2);
    }

    public function test_create_product_review(): void
    {
        // Arrange
        $user = User::factory()->create([
            'first_name' => 'Анна',
            'last_name' => 'Сидорова',
        ]);
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create();
        $order = Order::factory()
            ->for($user)
            ->for($store)
            ->delivered()
            ->create();
        OrderItem::factory()
            ->for($order)
            ->for($product)
            ->create();

        // Act
        $response = $this->actingAs($user)->postJson("/api/v1/products/{$product->id}/reviews", [
            'rating' => 5,
            'description' => 'Вкусный продукт!',
        ]);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('rating', 5)
            ->assertJsonPath('description', 'Вкусный продукт!')
            ->assertJsonPath('authorName', 'Анна С.')
            ->assertJsonPath('isPublished', false);

        $this->assertDatabaseHas('product_reviews', [
            'product_id' => $product->id,
            'user_id' => $user->id,
            'rating' => 5,
            'is_published' => false,
        ]);
    }

    public function test_cannot_review_without_purchase(): void
    {
        // Arrange
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create();
        // Есть заказ, но без этого товара
        Order::factory()->for($user)->for($store)->delivered()->create();

        // Act
        $response = $this->actingAs($user)->postJson("/api/v1/products/{$product->id}/reviews", [
            'rating' => 5,
        ]);

        // Assert
        $response->assertForbidden()
            ->assertJsonPath('message', 'Вы не покупали этот товар');
    }

    public function test_cannot_review_twice(): void
    {
        // Arrange
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create();
        $order = Order::factory()->for($user)->for($store)->delivered()->create();
        OrderItem::factory()->for($order)->for($product)->create();
        // Уже есть отзыв
        ProductReview::factory()->for($user)->for($product)->for($store)->create();

        // Act
        $response = $this->actingAs($user)->postJson("/api/v1/products/{$product->id}/reviews", [
            'rating' => 4,
        ]);

        // Assert
        $response->assertUnprocessable()
            ->assertJsonPath('message', 'Вы уже оставляли отзыв');
    }

    public function test_update_product_review(): void
    {
        // Arrange
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create();
        ProductReview::factory()
            ->for($user)
            ->for($product)
            ->for($store)
            ->create([
                'rating' => 3,
                'description' => 'Нормально',
            ]);

        // Act
        $response = $this->actingAs($user)->putJson("/api/v1/products/{$product->id}/reviews", [
            'rating' => 5,
            'description' => 'Теперь нравится больше!',
        ]);

        // Assert
        $response->assertOk()
            ->assertJsonPath('rating', 5)
            ->assertJsonPath('description', 'Теперь нравится больше!');

        $this->assertDatabaseHas('product_reviews', [
            'product_id' => $product->id,
            'user_id' => $user->id,
            'rating' => 5,
        ]);
    }

    public function test_get_my_product_review(): void
    {
        // Arrange
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create();
        ProductReview::factory()
            ->for($user)
            ->for($product)
            ->for($store)
            ->create(['rating' => 4, 'description' => 'Хороший продукт']);

        // Act
        $response = $this->actingAs($user)->getJson("/api/v1/products/{$product->id}/reviews/my");

        // Assert
        $response->assertOk()
            ->assertJsonPath('review.rating', 4)
            ->assertJsonPath('review.description', 'Хороший продукт')
            ->assertJsonPath('canReview', false);
    }

    public function test_get_my_product_review_returns_null_when_no_review(): void
    {
        // Arrange
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create();
        $order = Order::factory()->for($user)->for($store)->delivered()->create();
        OrderItem::factory()->for($order)->for($product)->create();

        // Act
        $response = $this->actingAs($user)->getJson("/api/v1/products/{$product->id}/reviews/my");

        // Assert
        $response->assertOk()
            ->assertJsonPath('review', null)
            ->assertJsonPath('canReview', true);
    }

    public function test_cannot_review_product_from_undelivered_order(): void
    {
        // Arrange
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create();
        $order = Order::factory()
            ->for($user)
            ->for($store)
            ->create(['status' => OrderStatus::Pending]);
        OrderItem::factory()->for($order)->for($product)->create();

        // Act
        $response = $this->actingAs($user)->postJson("/api/v1/products/{$product->id}/reviews", [
            'rating' => 5,
        ]);

        // Assert
        $response->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_create_review(): void
    {
        // Arrange
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create();

        // Act
        $response = $this->postJson("/api/v1/products/{$product->id}/reviews", [
            'rating' => 5,
        ]);

        // Assert
        $response->assertUnauthorized();
    }
}
