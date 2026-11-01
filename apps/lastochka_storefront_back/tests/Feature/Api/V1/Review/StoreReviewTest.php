<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Review;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StoreReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_store_reviews(): void
    {
        // Arrange
        $store = Store::factory()->create(['rating' => 4.5]);
        StoreReview::factory()
            ->for($store)
            ->published()
            ->count(3)
            ->create(['rating' => 5]);
        StoreReview::factory()
            ->for($store)
            ->published()
            ->count(2)
            ->create(['rating' => 4]);
        // Неопубликованный отзыв — не должен попасть в список
        StoreReview::factory()->for($store)->create(['is_published' => false]);

        // Act
        $response = $this->getJson("/api/v1/stores/{$store->id}/reviews");

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
            ->assertJsonPath('ratingsCount.4', 2);
    }

    public function test_create_store_review(): void
    {
        // Arrange
        $user = User::factory()->create([
            'first_name' => 'Иван',
            'last_name' => 'Петров',
        ]);
        $store = Store::factory()->create();
        Order::factory()
            ->for($user)
            ->for($store)
            ->delivered()
            ->create();

        // Act
        $response = $this->actingAs($user)->postJson("/api/v1/stores/{$store->id}/reviews", [
            'rating' => 5,
            'description' => 'Отличный магазин!',
        ]);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('rating', 5)
            ->assertJsonPath('description', 'Отличный магазин!')
            ->assertJsonPath('authorName', 'Иван П.')
            ->assertJsonPath('isPublished', false);

        $this->assertDatabaseHas('store_reviews', [
            'store_id' => $store->id,
            'user_id' => $user->id,
            'rating' => 5,
            'is_published' => false,
        ]);
    }

    public function test_cannot_review_without_delivered_order(): void
    {
        // Arrange
        $user = User::factory()->create();
        $store = Store::factory()->create();
        // Заказ НЕ доставлен (pending)
        Order::factory()
            ->for($user)
            ->for($store)
            ->create(['status' => OrderStatus::Pending]);

        // Act
        $response = $this->actingAs($user)->postJson("/api/v1/stores/{$store->id}/reviews", [
            'rating' => 5,
        ]);

        // Assert
        $response->assertForbidden()
            ->assertJsonPath('message', 'Нет доставленных заказов в этом магазине');
    }

    public function test_cannot_review_twice(): void
    {
        // Arrange
        $user = User::factory()->create();
        $store = Store::factory()->create();
        Order::factory()->for($user)->for($store)->delivered()->create();
        // Уже есть отзыв
        StoreReview::factory()->for($user)->for($store)->create();

        // Act
        $response = $this->actingAs($user)->postJson("/api/v1/stores/{$store->id}/reviews", [
            'rating' => 4,
        ]);

        // Assert
        $response->assertUnprocessable()
            ->assertJsonPath('message', 'Вы уже оставляли отзыв');
    }

    public function test_update_store_review(): void
    {
        // Arrange
        $user = User::factory()->create();
        $store = Store::factory()->create();
        StoreReview::factory()
            ->for($user)
            ->for($store)
            ->create([
                'rating' => 3,
                'description' => 'Нормально',
            ]);

        // Act
        $response = $this->actingAs($user)->putJson("/api/v1/stores/{$store->id}/reviews", [
            'rating' => 5,
            'description' => 'Отличный магазин, изменил мнение!',
        ]);

        // Assert
        $response->assertOk()
            ->assertJsonPath('rating', 5)
            ->assertJsonPath('description', 'Отличный магазин, изменил мнение!');

        $this->assertDatabaseHas('store_reviews', [
            'store_id' => $store->id,
            'user_id' => $user->id,
            'rating' => 5,
        ]);
    }

    public function test_update_nonexistent_review_returns_404(): void
    {
        // Arrange
        $user = User::factory()->create();
        $store = Store::factory()->create();

        // Act
        $response = $this->actingAs($user)->putJson("/api/v1/stores/{$store->id}/reviews", [
            'rating' => 5,
        ]);

        // Assert
        $response->assertNotFound();
    }

    public function test_rating_calculation(): void
    {
        // Arrange
        $store = Store::factory()->create();
        StoreReview::factory()
            ->for($store)
            ->published()
            ->create(['rating' => 5]);
        StoreReview::factory()
            ->for($store)
            ->published()
            ->create(['rating' => 4]);
        StoreReview::factory()
            ->for($store)
            ->published()
            ->create(['rating' => 3]);

        // Act — создаём ещё один отзыв, что триггерит пересчёт
        $user = User::factory()->create();
        Order::factory()->for($user)->for($store)->delivered()->create();
        $this->actingAs($user)->postJson("/api/v1/stores/{$store->id}/reviews", [
            'rating' => 5,
        ]);

        // Assert — рейтинг пересчитался с учётом всех отзывов (включая неопубликованные)
        // (5 + 4 + 3 + 5) / 4 = 4.25, округляется до 4.3
        $store->refresh();
        $this->assertEquals('4.3', $store->rating);
        $this->assertEquals(4, $store->reviews_count);
    }

    public function test_get_my_store_review(): void
    {
        // Arrange
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $review = StoreReview::factory()
            ->for($user)
            ->for($store)
            ->create(['rating' => 4, 'description' => 'Хороший магазин']);

        // Act
        $response = $this->actingAs($user)->getJson("/api/v1/stores/{$store->id}/reviews/my");

        // Assert
        $response->assertOk()
            ->assertJsonPath('review.rating', 4)
            ->assertJsonPath('review.description', 'Хороший магазин')
            ->assertJsonPath('canReview', false);
    }

    public function test_get_my_store_review_returns_null_when_no_review(): void
    {
        // Arrange
        $user = User::factory()->create();
        $store = Store::factory()->create();
        Order::factory()->for($user)->for($store)->delivered()->create();

        // Act
        $response = $this->actingAs($user)->getJson("/api/v1/stores/{$store->id}/reviews/my");

        // Assert
        $response->assertOk()
            ->assertJsonPath('review', null)
            ->assertJsonPath('canReview', true);
    }

    public function test_unauthenticated_user_cannot_create_review(): void
    {
        // Arrange
        $store = Store::factory()->create();

        // Act
        $response = $this->postJson("/api/v1/stores/{$store->id}/reviews", [
            'rating' => 5,
        ]);

        // Assert
        $response->assertUnauthorized();
    }

    public function test_validation_errors(): void
    {
        // Arrange
        $user = User::factory()->create();
        $store = Store::factory()->create();
        Order::factory()->for($user)->for($store)->delivered()->create();

        // Act — rating слишком большой
        $response = $this->actingAs($user)->postJson("/api/v1/stores/{$store->id}/reviews", [
            'rating' => 10,
        ]);

        // Assert
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['rating']);
    }
}
