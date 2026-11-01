<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Store;
use App\Models\StoreReview;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StoreReviewEventHandlerTest extends TestCase
{
    use RefreshDatabase;

    private EventConsumer $consumer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consumer = new EventConsumer('store_reviews', config('rabbitmq-events.entities.store_reviews'));
    }

    public function test_store_review_created_event_creates_review(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001', 'rating' => null, 'reviews_count' => 0]);

        EventSyncMetadata::create([
            'entity_type' => 'shops',
            'entity_id' => $store->id,
            'external_id' => 'shop_1',
            'source' => 'bagisto',
            'shop_code' => 'MSK_001',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_id' => 'shop_1',
            'shop_source' => 'bagisto',
            'rating' => 5,
            'description' => 'Отличный магазин!',
            'is_published' => true,
            'author_name' => 'Иван Иванов',
            'author_phone' => '+79991234567',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $this->assertDatabaseHas('store_reviews', [
            'store_id' => $store->id,
            'source' => 'bagisto',
            'rating' => 5,
            'description' => 'Отличный магазин!',
            'is_published' => true,
            'author_name' => 'Иван Иванов',
            'user_id' => null, // Внешний отзыв без user
        ]);
    }

    public function test_store_review_created_recalculates_rating(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_002', 'rating' => null, 'reviews_count' => 0]);

        EventSyncMetadata::create([
            'entity_type' => 'shops',
            'entity_id' => $store->id,
            'external_id' => 'shop_2',
            'source' => 'bagisto',
            'shop_code' => 'MSK_002',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_id' => 'shop_2',
            'shop_source' => 'bagisto',
            'rating' => 5,
            'is_published' => true,
        ]);

        $this->consumer->processEvent($eventData);

        $store->refresh();
        $this->assertEquals(5.0, (float) $store->rating);
        $this->assertEquals(1, $store->reviews_count);
    }

    public function test_unpublished_review_does_not_affect_rating(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_003', 'rating' => null, 'reviews_count' => 0]);

        EventSyncMetadata::create([
            'entity_type' => 'shops',
            'entity_id' => $store->id,
            'external_id' => 'shop_3',
            'source' => 'bagisto',
            'shop_code' => 'MSK_003',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_id' => 'shop_3',
            'shop_source' => 'bagisto',
            'rating' => 1,
            'is_published' => false, // Не опубликован
        ]);

        $this->consumer->processEvent($eventData);

        $store->refresh();
        $this->assertNull($store->rating); // Рейтинг не изменился
        $this->assertEquals(0, $store->reviews_count);
    }

    public function test_multiple_reviews_calculate_average_rating(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_004', 'rating' => null, 'reviews_count' => 0]);

        EventSyncMetadata::create([
            'entity_type' => 'shops',
            'entity_id' => $store->id,
            'external_id' => 'shop_4',
            'source' => 'bagisto',
            'shop_code' => 'MSK_004',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        // Первый отзыв: 5 звёзд
        $this->consumer->processEvent($this->buildEventData([
            'id' => 'review_1',
            'event_type' => 'created',
            'shop_id' => 'shop_4',
            'shop_source' => 'bagisto',
            'rating' => 5,
            'is_published' => true,
        ]));

        // Второй отзыв: 3 звезды
        $this->consumer->processEvent($this->buildEventData([
            'id' => 'review_2',
            'event_type' => 'created',
            'shop_id' => 'shop_4',
            'shop_source' => 'bagisto',
            'rating' => 3,
            'is_published' => true,
        ]));

        $store->refresh();
        $this->assertEquals(4.0, (float) $store->rating); // (5 + 3) / 2 = 4
        $this->assertEquals(2, $store->reviews_count);
    }

    public function test_store_review_updated_event_updates_review(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_005']);

        EventSyncMetadata::create([
            'entity_type' => 'shops',
            'entity_id' => $store->id,
            'external_id' => 'shop_5',
            'source' => 'bagisto',
            'shop_code' => 'MSK_005',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        // Создаём отзыв
        $review = StoreReview::factory()->external()->create([
            'store_id' => $store->id,
            'rating' => 3,
            'is_published' => false,
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'store_reviews',
            'entity_id' => $review->id,
            'external_id' => 'review_5',
            'source' => 'bagisto',
            // Пакет ищет metadata по shop_code (global_scope=false); событие без
            // shop_code в payload резолвится в config('rabbitmq-events.shop_code')
            'shop_code' => 'default',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        // Обновляем: публикуем и меняем рейтинг
        $eventData = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'review_5',
            'shop_id' => 'shop_5',
            'shop_source' => 'bagisto',
            'rating' => 5,
            'is_published' => true,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $review->refresh();
        $this->assertEquals(5, $review->rating);
        $this->assertTrue($review->is_published);

        // Рейтинг магазина пересчитан
        $store->refresh();
        $this->assertEquals(5.0, (float) $store->rating);
        $this->assertEquals(1, $store->reviews_count);
    }

    public function test_store_review_deleted_event_soft_deletes_review(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_006', 'rating' => 5.0, 'reviews_count' => 1]);

        EventSyncMetadata::create([
            'entity_type' => 'shops',
            'entity_id' => $store->id,
            'external_id' => 'shop_6',
            'source' => 'bagisto',
            'shop_code' => 'MSK_006',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $review = StoreReview::factory()->external()->published()->create([
            'store_id' => $store->id,
            'rating' => 5,
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'store_reviews',
            'entity_id' => $review->id,
            'external_id' => 'review_6',
            'source' => 'bagisto',
            // Пакет ищет metadata по shop_code (global_scope=false); событие без
            // shop_code в payload резолвится в config('rabbitmq-events.shop_code')
            'shop_code' => 'default',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'deleted',
            'id' => 'review_6',
            'shop_id' => 'shop_6',
            'shop_source' => 'bagisto',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);
        $this->assertSoftDeleted('store_reviews', ['id' => $review->id]);

        // Рейтинг магазина обнулён
        $store->refresh();
        $this->assertNull($store->rating);
        $this->assertEquals(0, $store->reviews_count);
    }

    public function test_review_event_without_store_is_skipped(): void
    {
        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_id' => 'non_existent_shop',
            'shop_source' => 'bagisto',
            'rating' => 5,
            'is_published' => true,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);
        $this->assertDatabaseCount('store_reviews', 0);
    }

    public function test_store_can_be_resolved_by_code_fallback(): void
    {
        // Магазин без metadata, но с code = shop_id
        $store = Store::factory()->create(['code' => 'shop_fallback', 'rating' => null]);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_id' => 'shop_fallback', // Совпадает с code магазина
            'shop_source' => 'bagisto',
            'rating' => 4,
            'is_published' => true,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $this->assertDatabaseHas('store_reviews', [
            'store_id' => $store->id,
            'rating' => 4,
        ]);
    }

    public function test_store_review_updated_event_preserves_user_id(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_007']);
        $user = \App\Models\User::factory()->create();

        EventSyncMetadata::create([
            'entity_type' => 'shops',
            'entity_id' => $store->id,
            'external_id' => 'shop_7',
            'source' => 'bagisto',
            'shop_code' => 'MSK_007',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        // Создаём отзыв с user_id (локальный отзыв пользователя)
        $review = StoreReview::factory()->create([
            'store_id' => $store->id,
            'user_id' => $user->id,
            'rating' => 3,
            'is_published' => false,
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'store_reviews',
            'entity_id' => $review->id,
            'external_id' => 'review_7',
            'source' => 'bagisto',
            // Пакет ищет metadata по shop_code (global_scope=false); событие без
            // shop_code в payload резолвится в config('rabbitmq-events.shop_code')
            'shop_code' => 'default',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        // Обновляем через RabbitMQ (модерация: публикуем отзыв)
        $eventData = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'review_7',
            'shop_id' => 'shop_7',
            'shop_source' => 'bagisto',
            'rating' => 4,
            'is_published' => true,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $review->refresh();
        $this->assertEquals(4, $review->rating);
        $this->assertTrue($review->is_published);
        // ГЛАВНОЕ: user_id НЕ должен сбрасываться в null
        $this->assertEquals($user->id, $review->user_id);
    }

    /**
     * Построить данные события с дефолтными значениями.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildEventData(array $overrides = []): array
    {
        return array_merge([
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'created',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'review_'.Str::random(5),
            'source' => 'bagisto',
            'shop_id' => 'shop_test',
            'shop_source' => 'bagisto',
            'rating' => 5,
            'description' => 'Тестовый отзыв',
            'is_published' => true,
            'author_name' => 'Тестовый пользователь',
        ], $overrides);
    }
}
