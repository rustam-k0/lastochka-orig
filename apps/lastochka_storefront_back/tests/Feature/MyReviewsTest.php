<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderReview;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MyReviewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/v1/reviews/my')
            ->assertStatus(401);
    }

    public function test_returns_only_current_user_reviews(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        ProductReview::factory()->count(2)->create(['user_id' => $user->id]);
        ProductReview::factory()->count(3)->create(['user_id' => $other->id]);
        OrderReview::factory()->create(['user_id' => $user->id]);
        OrderReview::factory()->create(['user_id' => $other->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reviews/my');

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'data');
        $response->assertJsonPath('total', 3);
        $response->assertJsonPath('hasMore', false);
    }

    public function test_mixes_product_and_order_reviews_sorted_by_created_at_desc(): void
    {
        $user = User::factory()->create();

        $oldProduct = ProductReview::factory()->create([
            'user_id' => $user->id,
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);
        $midOrder = OrderReview::factory()->create([
            'user_id' => $user->id,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);
        $newProduct = ProductReview::factory()->create([
            'user_id' => $user->id,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reviews/my');

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'data');

        $data = $response->json('data');

        $this->assertSame('product', $data[0]['type']);
        $this->assertSame($newProduct->id, $data[0]['id']);

        $this->assertSame('order', $data[1]['type']);
        $this->assertSame($midOrder->id, $data[1]['id']);

        $this->assertSame('product', $data[2]['type']);
        $this->assertSame($oldProduct->id, $data[2]['id']);
    }

    public function test_paginates_with_has_more_and_total(): void
    {
        $user = User::factory()->create();

        ProductReview::factory()->count(5)->create(['user_id' => $user->id]);
        OrderReview::factory()->count(3)->create(['user_id' => $user->id]);

        $page1 = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reviews/my?page=1&perPage=5');

        $page1->assertStatus(200);
        $page1->assertJsonCount(5, 'data');
        $page1->assertJsonPath('total', 8);
        $page1->assertJsonPath('hasMore', true);

        $page2 = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reviews/my?page=2&perPage=5');

        $page2->assertStatus(200);
        $page2->assertJsonCount(3, 'data');
        $page2->assertJsonPath('total', 8);
        $page2->assertJsonPath('hasMore', false);
    }

    public function test_per_page_is_capped_at_100(): void
    {
        $user = User::factory()->create();
        ProductReview::factory()->count(2)->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reviews/my?perPage=999');

        $response->assertStatus(200);
        $response->assertJsonPath('total', 2);
    }

    public function test_product_review_contains_required_fields(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['title' => 'Сыр Тильзитер 200г']);
        ProductImage::factory()->create([
            'product_id' => $product->id,
            'path' => 'products/tilzit.jpg',
            'position' => 0,
        ]);

        $review = ProductReview::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'rating' => 5,
            'description' => 'Очень вкусно',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reviews/my');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.id', $review->id);
        $response->assertJsonPath('data.0.type', 'product');
        $response->assertJsonPath('data.0.targetId', $product->id);
        $response->assertJsonPath('data.0.targetTitle', 'Сыр Тильзитер 200г');
        $response->assertJsonPath('data.0.rating', 5);
        $response->assertJsonPath('data.0.description', 'Очень вкусно');
        $response->assertJsonPath('data.0.canEdit', true);
        $response->assertJsonPath('data.0.canDelete', false);
        $this->assertNotNull($response->json('data.0.targetImageUrl'));
        $this->assertNotNull($response->json('data.0.createdAt'));
        $this->assertNotNull($response->json('data.0.updatedAt'));
    }

    public function test_order_review_has_synthetic_title_and_null_image(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);

        $review = OrderReview::factory()->create([
            'user_id' => $user->id,
            'order_id' => $order->id,
            'rating' => 4,
            'description' => 'Долго везли',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reviews/my');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.id', $review->id);
        $response->assertJsonPath('data.0.type', 'order');
        $response->assertJsonPath('data.0.targetId', $order->id);
        $response->assertJsonPath('data.0.targetTitle', "Заказ №{$order->id}");
        $response->assertJsonPath('data.0.targetImageUrl', null);
        $response->assertJsonPath('data.0.rating', 4);
        $response->assertJsonPath('data.0.description', 'Долго везли');
    }

    public function test_product_without_image_returns_null_image_url(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        ProductReview::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reviews/my');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.targetImageUrl', null);
    }

    public function test_soft_deleted_reviews_are_excluded(): void
    {
        $user = User::factory()->create();

        $alive = ProductReview::factory()->create(['user_id' => $user->id]);
        $deleted = ProductReview::factory()->create(['user_id' => $user->id]);
        $deleted->delete();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reviews/my');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('total', 1);
        $response->assertJsonPath('data.0.id', $alive->id);
    }
}
