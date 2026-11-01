<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OrderReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_review_for_delivered_order(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::Delivered,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/orders/{$order->id}/review", [
                'rating' => 5,
                'description' => 'Отличная доставка!',
            ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'order_id',
                'rating',
                'description',
                'author_name',
                'author_phone',
                'created_at',
                'updated_at',
            ],
        ]);

        $this->assertDatabaseHas('order_reviews', [
            'order_id' => $order->id,
            'user_id' => $user->id,
            'rating' => 5,
            'description' => 'Отличная доставка!',
            'author_name' => $user->short_name,
            'author_phone' => $user->phone,
        ]);
    }

    public function test_user_cannot_create_review_for_non_delivered_order(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::Pending,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/orders/{$order->id}/review", [
                'rating' => 5,
                'description' => 'Отличная доставка!',
            ]);

        $response->assertStatus(403);
    }

    public function test_user_can_update_existing_review(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::Delivered,
        ]);

        $review = OrderReview::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'rating' => 4,
            'description' => 'Хорошо',
            'author_name' => $user->short_name,
            'author_phone' => $user->phone,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/orders/{$order->id}/review", [
                'rating' => 5,
                'description' => 'Отлично!',
            ]);

        $response->assertStatus(200);

        $review->refresh();
        $this->assertEquals(5, $review->rating);
        $this->assertEquals('Отлично!', $review->description);
    }

    public function test_user_can_get_their_order_review(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
        ]);

        $review = OrderReview::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'rating' => 4,
            'description' => 'Хорошо',
            'author_name' => $user->short_name,
            'author_phone' => $user->phone,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/orders/{$order->id}/review");

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'id' => $review->id,
                'order_id' => $order->id,
                'rating' => 4,
                'description' => 'Хорошо',
            ],
        ]);
    }

    public function test_user_cannot_review_other_users_order(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user1->id,
            'status' => OrderStatus::Delivered,
        ]);

        $response = $this->actingAs($user2, 'sanctum')
            ->postJson("/api/v1/orders/{$order->id}/review", [
                'rating' => 5,
                'description' => 'Отличная доставка!',
            ]);

        $response->assertStatus(403);
    }

    public function test_user_cannot_create_duplicate_review_for_same_order(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::Delivered,
        ]);

        // Создаем первый отзыв
        OrderReview::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'rating' => 4,
            'description' => 'Первый отзыв',
            'author_name' => $user->short_name,
            'author_phone' => $user->phone,
        ]);

        // Пытаемся создать второй отзыв на тот же заказ
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/orders/{$order->id}/review", [
                'rating' => 5,
                'description' => 'Попытка второго отзыва',
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure([
            'message',
            'errors' => [
                'review',
            ],
        ]);

        // Убеждаемся, что в базе остался только один отзыв
        $this->assertEquals(1, OrderReview::where('order_id', $order->id)->count());
    }
}
