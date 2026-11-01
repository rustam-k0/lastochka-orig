<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Product;
use App\Models\Store;
use App\Models\Story;
use App\Models\StoryImage;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StoryEventHandlerTest extends TestCase
{
    use RefreshDatabase;

    private EventConsumer $consumer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consumer = new EventConsumer('stories', config('rabbitmq-events.entities.stories'));

        Storage::fake('public');
    }

    public function test_story_created_event_creates_story(): void
    {
        Http::fake([
            'https://cdn.example.com/*' => Http::response(
                file_get_contents(__DIR__.'/../../fixtures/test_image.png') ?: 'fake-image-content',
                200,
                ['Content-Type' => 'image/png']
            ),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'title' => 'Новинки недели',
            'expires_at' => now()->addWeek()->toIso8601String(),
            'link_type' => 'none',
            'is_active' => true,
            'thumbnail_image_url' => 'https://cdn.example.com/story_thumb.jpg',
            'images' => [
                ['id' => 'img_1', 'url' => 'https://cdn.example.com/story1.jpg'],
                ['id' => 'img_2', 'url' => 'https://cdn.example.com/story2.jpg'],
            ],
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $this->assertDatabaseHas('stories', [
            'source' => 'bagisto',
            'title' => 'Новинки недели',
            'link_type' => 'none',
            'is_active' => true,
        ]);

        $story = Story::where('source', 'bagisto')->first();
        $this->assertNotNull($story);
        $this->assertNotEmpty($story->thumbnail_path);
        $this->assertCount(2, $story->images);
    }

    public function test_story_with_product_link(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $product = Product::factory()->create(['store_id' => $store->id]);

        EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $product->id,
            'external_id' => 'prod_123',
            'source' => 'bagisto',
            'shop_code' => 'MSK_001',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'title' => 'Товар недели',
            'link_type' => 'product',
            'product_id' => 'prod_123',
            'product_source' => 'bagisto',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $story = Story::where('source', 'bagisto')->first();
        $this->assertNotNull($story);
        $this->assertEquals('product', $story->link_type->value);
        $this->assertEquals($product->id, $story->link_product_id);
    }

    public function test_story_with_store_link(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_002']);

        EventSyncMetadata::create([
            'entity_type' => 'shops',
            'entity_id' => $store->id,
            'external_id' => 'shop_1',
            'source' => 'bagisto',
            'shop_code' => 'MSK_002',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'title' => 'Магазин месяца',
            'link_type' => 'store',
            'shop_id' => 'shop_1',
            'shop_source' => 'bagisto',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $story = Story::where('source', 'bagisto')->first();
        $this->assertNotNull($story);
        $this->assertEquals('store', $story->link_type->value);
        $this->assertEquals($store->id, $story->link_store_id);
    }

    public function test_story_with_external_link(): void
    {
        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'title' => 'Подробнее на сайте',
            'link_type' => 'external',
            'external_url' => 'https://promo.example.com/action',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $story = Story::where('source', 'bagisto')->first();
        $this->assertNotNull($story);
        $this->assertEquals('external', $story->link_type->value);
        $this->assertEquals('https://promo.example.com/action', $story->link_external_url);
    }

    public function test_story_updated_event_updates_story(): void
    {
        $story = Story::factory()->create([
            'source' => 'bagisto',
            'external_id' => 'story_update',
            'title' => 'Старое название',
            'is_active' => true,
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'stories',
            'entity_id' => $story->id,
            'external_id' => 'story_update',
            'source' => 'bagisto',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'story_update',
            'title' => 'Новое название',
            'is_active' => false,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $story->refresh();
        $this->assertEquals('Новое название', $story->title);
        $this->assertFalse($story->is_active);
    }

    public function test_story_deleted_event_soft_deletes_story(): void
    {
        $story = Story::factory()->create([
            'source' => 'bagisto',
            'external_id' => 'story_delete',
            'title' => 'История для удаления',
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'stories',
            'entity_id' => $story->id,
            'external_id' => 'story_delete',
            'source' => 'bagisto',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'deleted',
            'id' => 'story_delete',
            'title' => 'История для удаления',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $this->assertSoftDeleted('stories', ['id' => $story->id]);
    }

    public function test_images_replaced_on_update(): void
    {
        Http::fake([
            'https://cdn.example.com/*' => Http::response('fake-image-content', 200, ['Content-Type' => 'image/png']),
        ]);

        $story = Story::factory()->create([
            'source' => 'bagisto',
            'external_id' => 'story_images',
        ]);

        // Создаём старые изображения
        StoryImage::create(['story_id' => $story->id, 'path' => 'old/image1.jpg', 'position' => 0]);
        StoryImage::create(['story_id' => $story->id, 'path' => 'old/image2.jpg', 'position' => 1]);

        EventSyncMetadata::create([
            'entity_type' => 'stories',
            'entity_id' => $story->id,
            'external_id' => 'story_images',
            'source' => 'bagisto',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'story_images',
            'title' => 'С новыми изображениями',
            'images' => [
                ['id' => 'new_1', 'url' => 'https://cdn.example.com/new1.jpg'],
            ],
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        // Старые изображения должны быть удалены, новое добавлено
        $this->assertCount(1, $story->fresh()->images);
        $this->assertStringStartsWith('stories/', $story->fresh()->images->first()->path);
    }

    public function test_duplicate_event_is_skipped(): void
    {
        $eventId = Str::uuid()->toString();

        $eventData = $this->buildEventData([
            'event_id' => $eventId,
            'event_type' => 'created',
            'title' => 'Уникальная история',
        ]);

        $result1 = $this->consumer->processEvent($eventData);
        $this->assertEquals('ack', $result1);

        $result2 = $this->consumer->processEvent($eventData);
        $this->assertEquals('ack', $result2);

        $this->assertEquals(1, Story::where('source', 'bagisto')->count());
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
            'id' => 'story_'.Str::random(5),
            'source' => 'bagisto',
            'title' => 'Тестовая история',
            'expires_at' => now()->addMonth()->toIso8601String(),
            'link_type' => 'none',
            'is_active' => true,
        ], $overrides);
    }
}
