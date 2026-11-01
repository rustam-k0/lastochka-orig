<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Store;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProductReviewEventHandlerTest extends TestCase
{
    use RefreshDatabase;

    private EventConsumer $consumer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consumer = new EventConsumer('product_reviews', config('rabbitmq-events.entities.product_reviews'));
    }

    public function test_product_review_created_event_creates_review(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $product = Product::factory()->create(['store_id' => $store->id, 'rating' => null, 'reviews_count' => 0]);

        $this->createShopMetadata($store, 'shop_1');
        $this->createProductMetadata($product, 'prod_1');

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'product_id' => 'prod_1',
            'product_source' => 'bagisto',
            'shop_id' => 'shop_1',
            'shop_source' => 'bagisto',
            'rating' => 5,
            'description' => 'Отличный товар!',
            'is_published' => true,
            'author_name' => 'Мария Петрова',
            'author_phone' => '+79997654321',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $this->assertDatabaseHas('product_reviews', [
            'product_id' => $product->id,
            'store_id' => $store->id,
            'source' => 'bagisto',
            'rating' => 5,
            'description' => 'Отличный товар!',
            'is_published' => true,
            'author_name' => 'Мария Петрова',
            'user_id' => null, // Внешний отзыв без user
        ]);
    }

    public function test_product_review_created_recalculates_rating(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_002']);
        $product = Product::factory()->create(['store_id' => $store->id, 'rating' => null, 'reviews_count' => 0]);

        $this->createShopMetadata($store, 'shop_2');
        $this->createProductMetadata($product, 'prod_2');

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'product_id' => 'prod_2',
            'product_source' => 'bagisto',
            'shop_id' => 'shop_2',
            'shop_source' => 'bagisto',
            'rating' => 4,
            'is_published' => true,
        ]);

        $this->consumer->processEvent($eventData);

        $product->refresh();
        $this->assertEquals(4.0, (float) $product->rating);
        $this->assertEquals(1, $product->reviews_count);
    }

    public function test_unpublished_review_does_not_affect_rating(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_003']);
        $product = Product::factory()->create(['store_id' => $store->id, 'rating' => null, 'reviews_count' => 0]);

        $this->createShopMetadata($store, 'shop_3');
        $this->createProductMetadata($product, 'prod_3');

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'product_id' => 'prod_3',
            'product_source' => 'bagisto',
            'shop_id' => 'shop_3',
            'shop_source' => 'bagisto',
            'rating' => 1,
            'is_published' => false, // Не опубликован
        ]);

        $this->consumer->processEvent($eventData);

        $product->refresh();
        $this->assertNull($product->rating); // Рейтинг не изменился
        $this->assertEquals(0, $product->reviews_count);
    }

    public function test_multiple_reviews_calculate_average_rating(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_004']);
        $product = Product::factory()->create(['store_id' => $store->id, 'rating' => null, 'reviews_count' => 0]);

        $this->createShopMetadata($store, 'shop_4');
        $this->createProductMetadata($product, 'prod_4');

        // Первый отзыв: 5 звёзд
        $this->consumer->processEvent($this->buildEventData([
            'id' => 'review_1',
            'event_type' => 'created',
            'product_id' => 'prod_4',
            'product_source' => 'bagisto',
            'shop_id' => 'shop_4',
            'shop_source' => 'bagisto',
            'rating' => 5,
            'is_published' => true,
        ]));

        // Второй отзыв: 3 звезды
        $this->consumer->processEvent($this->buildEventData([
            'id' => 'review_2',
            'event_type' => 'created',
            'product_id' => 'prod_4',
            'product_source' => 'bagisto',
            'shop_id' => 'shop_4',
            'shop_source' => 'bagisto',
            'rating' => 3,
            'is_published' => true,
        ]));

        // Третий отзыв: 4 звезды
        $this->consumer->processEvent($this->buildEventData([
            'id' => 'review_3',
            'event_type' => 'created',
            'product_id' => 'prod_4',
            'product_source' => 'bagisto',
            'shop_id' => 'shop_4',
            'shop_source' => 'bagisto',
            'rating' => 4,
            'is_published' => true,
        ]));

        $product->refresh();
        $this->assertEquals(4.0, (float) $product->rating); // (5 + 3 + 4) / 3 = 4
        $this->assertEquals(3, $product->reviews_count);
    }

    public function test_product_review_updated_event_updates_review(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_005']);
        $product = Product::factory()->create(['store_id' => $store->id]);

        $this->createShopMetadata($store, 'shop_5');
        $this->createProductMetadata($product, 'prod_5');

        // Создаём отзыв
        $review = ProductReview::factory()->external()->create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'rating' => 2,
            'is_published' => false,
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'product_reviews',
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
            'product_id' => 'prod_5',
            'product_source' => 'bagisto',
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

        // Рейтинг товара пересчитан
        $product->refresh();
        $this->assertEquals(5.0, (float) $product->rating);
        $this->assertEquals(1, $product->reviews_count);
    }

    public function test_product_review_deleted_event_soft_deletes_review(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_006']);
        $product = Product::factory()->create(['store_id' => $store->id, 'rating' => 5.0, 'reviews_count' => 1]);

        $this->createShopMetadata($store, 'shop_6');
        $this->createProductMetadata($product, 'prod_6');

        $review = ProductReview::factory()->external()->published()->create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'rating' => 5,
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'product_reviews',
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
            'product_id' => 'prod_6',
            'product_source' => 'bagisto',
            'shop_id' => 'shop_6',
            'shop_source' => 'bagisto',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);
        $this->assertSoftDeleted('product_reviews', ['id' => $review->id]);

        // Рейтинг товара обнулён
        $product->refresh();
        $this->assertNull($product->rating);
        $this->assertEquals(0, $product->reviews_count);
    }

    public function test_review_event_without_product_is_skipped(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_007']);
        $this->createShopMetadata($store, 'shop_7');

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'product_id' => 'non_existent_product',
            'product_source' => 'bagisto',
            'shop_id' => 'shop_7',
            'shop_source' => 'bagisto',
            'rating' => 5,
            'is_published' => true,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);
        $this->assertDatabaseCount('product_reviews', 0);
    }

    public function test_review_event_without_store_is_skipped(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_008']);
        $product = Product::factory()->create(['store_id' => $store->id]);
        $this->createProductMetadata($product, 'prod_8');

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'product_id' => 'prod_8',
            'product_source' => 'bagisto',
            'shop_id' => 'non_existent_shop',
            'shop_source' => 'bagisto',
            'rating' => 5,
            'is_published' => true,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);
        $this->assertDatabaseCount('product_reviews', 0);
    }

    public function test_product_review_updated_event_preserves_user_id(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_009']);
        $product = Product::factory()->create(['store_id' => $store->id]);
        $user = \App\Models\User::factory()->create();

        $this->createShopMetadata($store, 'shop_9');
        $this->createProductMetadata($product, 'prod_9');

        // Создаём отзыв с user_id (локальный отзыв пользователя)
        $review = ProductReview::factory()->create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'user_id' => $user->id,
            'rating' => 3,
            'is_published' => false,
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'product_reviews',
            'entity_id' => $review->id,
            'external_id' => 'review_9',
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
            'id' => 'review_9',
            'product_id' => 'prod_9',
            'product_source' => 'bagisto',
            'shop_id' => 'shop_9',
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
     * Создать metadata для магазина.
     */
    private function createShopMetadata(Store $store, string $externalId): void
    {
        EventSyncMetadata::create([
            'entity_type' => 'shops',
            'entity_id' => $store->id,
            'external_id' => $externalId,
            'source' => 'bagisto',
            'shop_code' => $store->code,
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);
    }

    /**
     * Создать metadata для товара.
     */
    private function createProductMetadata(Product $product, string $externalId): void
    {
        EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $product->id,
            'external_id' => $externalId,
            'source' => 'bagisto',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);
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
            'product_id' => 'prod_test',
            'product_source' => 'bagisto',
            'shop_id' => 'shop_test',
            'shop_source' => 'bagisto',
            'rating' => 5,
            'description' => 'Тестовый отзыв на товар',
            'is_published' => true,
            'author_name' => 'Тестовый пользователь',
        ], $overrides);
    }
}
