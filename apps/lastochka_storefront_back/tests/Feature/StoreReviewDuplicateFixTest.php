<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreReview;
use App\Models\User;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StoreReviewDuplicateFixTest extends TestCase
{
    use RefreshDatabase;

    private function userWithDeliveredOrder(Store $store): User
    {
        $user = User::factory()->create();

        Order::factory()->create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'status' => OrderStatus::Delivered,
        ]);

        return $user;
    }

    /**
     * Кейс 500 из ALADDIN-11937: у пользователя был отзыв, его удалили
     * (soft delete) — повторное создание падало на UNIQUE(user_id, store_id),
     * т.к. индекс учитывает и trashed-строки.
     */
    public function test_can_recreate_review_after_soft_delete(): void
    {
        $store = Store::factory()->create();
        $user = $this->userWithDeliveredOrder($store);

        $old = StoreReview::create([
            'store_id' => $store->id,
            'user_id' => $user->id,
            'rating' => 2,
            'description' => 'Старый отзыв',
            'is_published' => false,
        ]);
        $old->delete();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/stores/{$store->id}/reviews", [
                'rating' => 5,
                'description' => 'Новый отзыв после удаления',
            ]);

        $response->assertStatus(201);

        $this->assertSame(1, StoreReview::withTrashed()
            ->where('user_id', $user->id)
            ->where('store_id', $store->id)
            ->count(), 'Trashed-отзыв должен быть восстановлен, а не задублирован');

        $this->assertDatabaseHas('store_reviews', [
            'user_id' => $user->id,
            'store_id' => $store->id,
            'rating' => 5,
            'description' => 'Новый отзыв после удаления',
            'deleted_at' => null,
        ]);
    }

    public function test_create_returns_422_when_review_already_exists(): void
    {
        $store = Store::factory()->create();
        $user = $this->userWithDeliveredOrder($store);

        StoreReview::create([
            'store_id' => $store->id,
            'user_id' => $user->id,
            'rating' => 4,
            'is_published' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/stores/{$store->id}/reviews", [
                'rating' => 5,
            ]);

        $response->assertStatus(422);
    }

    private function consumeStoreReviewEvent(Store $store, array $overrides = []): string
    {
        EventSyncMetadata::firstOrCreate(
            ['entity_type' => 'shops', 'entity_id' => $store->id, 'source' => 'admin_back'],
            [
                'external_id' => 'shop_' . $store->id,
                'shop_code' => $store->code,
                'last_event_id' => Str::uuid()->toString(),
                'last_event_timestamp' => now()->subHour(),
            ]
        );

        $consumer = new EventConsumer('store_reviews', config('rabbitmq-events.entities.store_reviews'));

        return $consumer->processEvent(array_merge([
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'created',
            'event_timestamp' => now()->toIso8601String(),
            'event_source' => 'admin_back',
            'id' => (string) mt_rand(100000, 999999),
            'source' => 'admin_back',
            'shop_id' => 'shop_' . $store->id,
            'shop_source' => 'admin_back',
            'rating' => 5,
            'description' => 'Отзыв из админки',
            'author_name' => 'Админ',
            'author_phone' => '+79990000000',
            'is_published' => true,
            'created_at' => now()->toIso8601String(),
        ], $overrides));
    }

    /**
     * Ожидание ALADDIN-11937: отзыв, созданный админом с номером клиента,
     * должен привязываться к этому клиенту — тогда мобилка через
     * GET /stores/{id}/reviews/my увидит его и предложит редактирование.
     */
    public function test_inbound_review_links_user_by_phone(): void
    {
        $store = Store::factory()->create();
        $user = User::factory()->create(['phone' => '+79674840663']);

        $result = $this->consumeStoreReviewEvent($store, ['author_phone' => '+79674840663']);

        $this->assertEquals('ack', $result);
        $this->assertDatabaseHas('store_reviews', [
            'store_id' => $store->id,
            'user_id' => $user->id,
            'author_phone' => '+79674840663',
        ]);
    }

    public function test_inbound_review_stays_unlinked_when_user_already_has_review(): void
    {
        $store = Store::factory()->create();
        $user = User::factory()->create(['phone' => '+79674840663']);

        StoreReview::create([
            'store_id' => $store->id,
            'user_id' => $user->id,
            'rating' => 3,
            'is_published' => true,
        ]);

        $result = $this->consumeStoreReviewEvent($store, ['author_phone' => '+79674840663']);

        $this->assertEquals('ack', $result);

        // Привязка невозможна (UNIQUE user_id+store_id) — отзыв остаётся анонимным
        $this->assertDatabaseHas('store_reviews', [
            'store_id' => $store->id,
            'user_id' => null,
            'author_phone' => '+79674840663',
        ]);
    }

    public function test_inbound_review_without_matching_user_stays_unlinked(): void
    {
        $store = Store::factory()->create();

        $result = $this->consumeStoreReviewEvent($store, ['author_phone' => '+70000000001']);

        $this->assertEquals('ack', $result);
        $this->assertDatabaseHas('store_reviews', [
            'store_id' => $store->id,
            'user_id' => null,
            'author_phone' => '+70000000001',
        ]);
    }
}
