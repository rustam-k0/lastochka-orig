<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Store;
use App\Models\StoreReview;
use App\Models\User;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ReviewPublisherTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_review_created_event_published(): void
    {
        $store = Store::factory()->create();
        $user = User::factory()->create();

        $review = StoreReview::create([
            'store_id' => $store->id,
            'user_id' => $user->id,
            'rating' => 5,
            'description' => 'Отлично!',
            'author_name' => 'Иван И.',
            'author_phone' => $user->phone,
        ]);

        $this->assertDatabaseHas('event_outbox', [
            'entity_type' => 'store_reviews',
            'entity_id' => $review->id,
            'event_type' => 'created',
        ]);

        $outbox = EventOutbox::where('entity_type', 'store_reviews')
            ->where('entity_id', $review->id)
            ->first();

        $this->assertNotNull($outbox);
        $this->assertEquals('merchant_review.created', $outbox->routing_key);

        $payload = $outbox->payload;

        $this->assertEquals((string) $review->id, $payload['id']);
        $this->assertEquals('storefront', $payload['source']);
        $this->assertEquals(5, $payload['rating']);
        $this->assertEquals('Отлично!', $payload['description']);
        $this->assertEquals('Иван И.', $payload['author_name']);
        $this->assertEquals((string) $user->id, $payload['user_id']);
        $this->assertEquals('storefront', $payload['user_source']);
        $this->assertArrayHasKey('event_id', $payload);
        $this->assertArrayHasKey('event_timestamp', $payload);
        $this->assertArrayHasKey('shop_id', $payload);
        $this->assertArrayHasKey('shop_source', $payload);
    }

    public function test_store_review_updated_event_published(): void
    {
        $store = Store::factory()->create();
        $user = User::factory()->create();

        $review = StoreReview::create([
            'store_id' => $store->id,
            'user_id' => $user->id,
            'rating' => 4,
            'description' => 'Хорошо',
            'author_name' => 'Мария М.',
            'author_phone' => $user->phone,
        ]);

        // Обновляем
        $review->update(['rating' => 5, 'description' => 'Отлично!']);

        $this->assertDatabaseHas('event_outbox', [
            'entity_type' => 'store_reviews',
            'entity_id' => $review->id,
            'event_type' => 'updated',
        ]);

        $outbox = EventOutbox::where('entity_type', 'store_reviews')
            ->where('entity_id', $review->id)
            ->where('event_type', 'updated')
            ->first();

        $this->assertNotNull($outbox);
        $this->assertEquals('merchant_review.updated', $outbox->routing_key);

        $payload = $outbox->payload;
        $this->assertEquals(5, $payload['rating']);
        $this->assertEquals('Отлично!', $payload['description']);
    }

    public function test_product_review_created_event_published(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create();
        $user = User::factory()->create();

        $review = ProductReview::create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'user_id' => $user->id,
            'rating' => 5,
            'description' => 'Вкусно!',
            'author_name' => 'Мария М.',
            'author_phone' => $user->phone,
        ]);

        $this->assertDatabaseHas('event_outbox', [
            'entity_type' => 'product_reviews',
            'entity_id' => $review->id,
            'event_type' => 'created',
        ]);

        $outbox = EventOutbox::where('entity_type', 'product_reviews')
            ->where('entity_id', $review->id)
            ->first();

        $this->assertNotNull($outbox);
        $this->assertEquals('product_review.created', $outbox->routing_key);

        $payload = $outbox->payload;

        $this->assertEquals((string) $review->id, $payload['id']);
        $this->assertEquals('storefront', $payload['source']);
        $this->assertEquals(5, $payload['rating']);
        $this->assertEquals('Вкусно!', $payload['description']);
        $this->assertArrayHasKey('product_id', $payload);
        $this->assertArrayHasKey('product_source', $payload);
        $this->assertArrayHasKey('shop_id', $payload);
        $this->assertArrayHasKey('shop_source', $payload);
        $this->assertArrayHasKey('user_id', $payload);
        $this->assertArrayHasKey('user_source', $payload);
    }

    public function test_external_store_review_not_published(): void
    {
        $store = Store::factory()->create();

        // Отзыв из внешней системы (без user_id, с source)
        $review = StoreReview::factory()->external('bagisto')->create([
            'store_id' => $store->id,
            'rating' => 4,
            'author_name' => 'Внешний отзыв',
        ]);

        $this->assertDatabaseMissing('event_outbox', [
            'entity_type' => 'store_reviews',
            'entity_id' => $review->id,
        ]);
    }

    public function test_external_product_review_not_published(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create();

        // Отзыв из внешней системы (без user_id, с source)
        $review = ProductReview::factory()->external('bagisto')->create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'rating' => 4,
            'author_name' => 'Внешний отзыв',
        ]);

        $this->assertDatabaseMissing('event_outbox', [
            'entity_type' => 'product_reviews',
            'entity_id' => $review->id,
        ]);
    }

    public function test_store_review_without_user_not_published(): void
    {
        $store = Store::factory()->create();

        // Отзыв без user_id (гостевой или внешний)
        $review = StoreReview::factory()->create([
            'store_id' => $store->id,
            'user_id' => null,
            'source' => null, // локальный, но без пользователя
            'rating' => 3,
        ]);

        $this->assertDatabaseMissing('event_outbox', [
            'entity_type' => 'store_reviews',
            'entity_id' => $review->id,
        ]);
    }

    public function test_product_review_includes_external_ids(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create();
        $user = User::factory()->create();

        // Создаём metadata для товара
        EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $product->id,
            'external_id' => 'ext_prod_123',
            'source' => 'bagisto',
            'shop_code' => $store->code,
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now(),
        ]);

        // Создаём metadata для магазина
        EventSyncMetadata::create([
            'entity_type' => 'shops',
            'entity_id' => $store->id,
            'external_id' => 'ext_shop_456',
            'source' => 'bagisto',
            'shop_code' => $store->code,
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now(),
        ]);

        $review = ProductReview::create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'user_id' => $user->id,
            'rating' => 5,
            'description' => 'Вкусно!',
            'author_name' => 'Мария М.',
        ]);

        $outbox = EventOutbox::where('entity_id', $review->id)
            ->where('entity_type', 'product_reviews')
            ->first();

        $payload = $outbox->payload;

        $this->assertEquals('ext_prod_123', $payload['product_id']);
        $this->assertEquals('bagisto', $payload['product_source']);
        $this->assertEquals('ext_shop_456', $payload['shop_id']);
        $this->assertEquals('bagisto', $payload['shop_source']);
    }

    public function test_store_review_includes_external_shop_id(): void
    {
        $store = Store::factory()->create();
        $user = User::factory()->create();

        // Создаём metadata для магазина
        EventSyncMetadata::create([
            'entity_type' => 'shops',
            'entity_id' => $store->id,
            'external_id' => 'ext_shop_789',
            'source' => 'bagisto',
            'shop_code' => $store->code,
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now(),
        ]);

        $review = StoreReview::create([
            'store_id' => $store->id,
            'user_id' => $user->id,
            'rating' => 5,
            'description' => 'Отлично!',
            'author_name' => 'Иван И.',
        ]);

        $outbox = EventOutbox::where('entity_id', $review->id)
            ->where('entity_type', 'store_reviews')
            ->first();

        $payload = $outbox->payload;

        $this->assertEquals('ext_shop_789', $payload['shop_id']);
        $this->assertEquals('bagisto', $payload['shop_source']);
    }

    public function test_store_review_payload_format(): void
    {
        $store = Store::factory()->create();
        $user = User::factory()->create();

        $review = StoreReview::create([
            'store_id' => $store->id,
            'user_id' => $user->id,
            'rating' => 4,
            'description' => 'Хороший магазин',
            'author_name' => 'Тест Тестов',
            'author_phone' => '+79991234567',
            'is_published' => false,
        ]);

        $outbox = EventOutbox::where('entity_type', 'store_reviews')
            ->where('entity_id', $review->id)
            ->first();

        $payload = $outbox->payload;

        // Проверяем все обязательные поля согласно aladdin_exchange.md
        $this->assertArrayHasKey('event_id', $payload);
        $this->assertArrayHasKey('event_timestamp', $payload);
        $this->assertArrayHasKey('event_source', $payload);
        $this->assertArrayHasKey('id', $payload);
        $this->assertArrayHasKey('source', $payload);
        $this->assertArrayHasKey('shop_id', $payload);
        $this->assertArrayHasKey('shop_source', $payload);
        $this->assertArrayHasKey('user_id', $payload);
        $this->assertArrayHasKey('user_source', $payload);
        $this->assertArrayHasKey('rating', $payload);
        $this->assertArrayHasKey('description', $payload);
        $this->assertArrayHasKey('author_name', $payload);
        $this->assertArrayHasKey('author_phone', $payload);
        $this->assertArrayHasKey('is_published', $payload);
        $this->assertArrayHasKey('created_at', $payload);

        $this->assertEquals('storefront', $payload['event_source']);
        $this->assertEquals('storefront', $payload['source']);
        $this->assertEquals(4, $payload['rating']);
        $this->assertEquals('Хороший магазин', $payload['description']);
        $this->assertEquals('Тест Тестов', $payload['author_name']);
        $this->assertEquals('+79991234567', $payload['author_phone']);
        $this->assertFalse($payload['is_published']);
    }

    public function test_product_review_payload_format(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create();
        $user = User::factory()->create();

        $review = ProductReview::create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'user_id' => $user->id,
            'rating' => 5,
            'description' => 'Отличный товар',
            'author_name' => 'Покупатель',
            'author_phone' => '+79997654321',
            'is_published' => true,
        ]);

        $outbox = EventOutbox::where('entity_type', 'product_reviews')
            ->where('entity_id', $review->id)
            ->first();

        $payload = $outbox->payload;

        // Проверяем все обязательные поля согласно aladdin_exchange.md
        $this->assertArrayHasKey('event_id', $payload);
        $this->assertArrayHasKey('event_timestamp', $payload);
        $this->assertArrayHasKey('event_source', $payload);
        $this->assertArrayHasKey('id', $payload);
        $this->assertArrayHasKey('source', $payload);
        $this->assertArrayHasKey('product_id', $payload);
        $this->assertArrayHasKey('product_source', $payload);
        $this->assertArrayHasKey('shop_id', $payload);
        $this->assertArrayHasKey('shop_source', $payload);
        $this->assertArrayHasKey('user_id', $payload);
        $this->assertArrayHasKey('user_source', $payload);
        $this->assertArrayHasKey('rating', $payload);
        $this->assertArrayHasKey('description', $payload);
        $this->assertArrayHasKey('author_name', $payload);
        $this->assertArrayHasKey('author_phone', $payload);
        $this->assertArrayHasKey('is_published', $payload);
        $this->assertArrayHasKey('created_at', $payload);

        $this->assertEquals('storefront', $payload['event_source']);
        $this->assertEquals('storefront', $payload['source']);
        $this->assertEquals(5, $payload['rating']);
        $this->assertEquals('Отличный товар', $payload['description']);
        $this->assertTrue($payload['is_published']);
    }
}
