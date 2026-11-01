<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Product;
use App\Models\Promotion;
use App\Models\Store;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PromotionEventHandlerTest extends TestCase
{
    use RefreshDatabase;

    private EventConsumer $consumer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consumer = new EventConsumer('merchant_sales', config('rabbitmq-events.entities.merchant_sales'));

        Storage::fake('public');
    }

    public function test_promotion_created_event_creates_promotion(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);

        Http::fake([
            'https://cdn.example.com/*' => Http::response('fake-image-content', 200, ['Content-Type' => 'image/png']),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_id' => 'MSK_001',
            'shop_source' => 'bagisto',
            'title' => 'Молочная неделя',
            'description' => 'Скидки на всю молочную продукцию',
            'image_url' => 'https://cdn.example.com/promo.jpg',
            'discount_percent' => 15,
            'starts_at' => now()->toIso8601String(),
            'ends_at' => now()->addWeek()->toIso8601String(),
            'is_active' => true,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $this->assertDatabaseHas('promotions', [
            'store_id' => $store->id,
            'source' => 'bagisto',
            'title' => 'Молочная неделя',
            'description' => 'Скидки на всю молочную продукцию',
            'discount_percent' => 15,
            'is_active' => true,
        ]);

        $promotion = Promotion::where('store_id', $store->id)->first();
        $this->assertNotNull($promotion);
        $this->assertNotEmpty($promotion->image_path);
    }

    public function test_promotion_with_products_sync(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_002']);
        $product1 = Product::factory()->create(['store_id' => $store->id]);
        $product2 = Product::factory()->create(['store_id' => $store->id]);

        EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $product1->id,
            'external_id' => 'prod_1',
            'source' => 'bagisto',
            'shop_code' => 'MSK_002',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $product2->id,
            'external_id' => 'prod_2',
            'source' => 'bagisto',
            'shop_code' => 'MSK_002',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_id' => 'MSK_002',
            'shop_source' => 'bagisto',
            'title' => 'Акция с товарами',
            'discount_percent' => 20,
            'products' => [
                ['product_id' => 'prod_1', 'product_source' => 'bagisto'],
                ['product_id' => 'prod_2', 'product_source' => 'bagisto'],
            ],
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $promotion = Promotion::where('store_id', $store->id)->first();
        $this->assertNotNull($promotion);
        $this->assertCount(2, $promotion->products);
        $this->assertTrue($promotion->products->contains($product1));
        $this->assertTrue($promotion->products->contains($product2));
    }

    public function test_promotion_products_updated_on_update(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_003']);
        $product1 = Product::factory()->create(['store_id' => $store->id]);
        $product2 = Product::factory()->create(['store_id' => $store->id]);
        $product3 = Product::factory()->create(['store_id' => $store->id]);

        $promotion = Promotion::factory()->create([
            'store_id' => $store->id,
            'source' => 'bagisto',
            'external_id' => 'promo_update',
        ]);
        $promotion->products()->attach([$product1->id, $product2->id]);

        EventSyncMetadata::create([
            'entity_type' => 'merchant_sales',
            'entity_id' => $promotion->id,
            'external_id' => 'promo_update',
            'source' => 'bagisto',
            'shop_code' => 'MSK_003',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $product3->id,
            'external_id' => 'prod_3',
            'source' => 'bagisto',
            'shop_code' => 'MSK_003',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'promo_update',
            'shop_id' => 'MSK_003',
            'shop_source' => 'bagisto',
            'title' => 'Обновлённая акция',
            'discount_percent' => 25,
            'products' => [
                ['product_id' => 'prod_3', 'product_source' => 'bagisto'],
            ],
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $promotion->refresh();
        $this->assertCount(1, $promotion->products);
        $this->assertTrue($promotion->products->contains($product3));
        $this->assertFalse($promotion->products->contains($product1));
    }

    public function test_promotion_deleted_event_soft_deletes_promotion(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_004']);
        $promotion = Promotion::factory()->create([
            'store_id' => $store->id,
            'source' => 'bagisto',
            'external_id' => 'promo_delete',
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'merchant_sales',
            'entity_id' => $promotion->id,
            'external_id' => 'promo_delete',
            'source' => 'bagisto',
            'shop_code' => 'MSK_004',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'deleted',
            'id' => 'promo_delete',
            'shop_id' => 'MSK_004',
            'shop_source' => 'bagisto',
            'title' => 'Удаляемая акция',
            'discount_percent' => 10,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $this->assertSoftDeleted('promotions', ['id' => $promotion->id]);
    }

    public function test_deleted_event_with_orphan_metadata_soft_deletes_promotion(): void
    {
        // Метаданные есть, но entity_id потерян: пакет ищет сущность через
        // findExistingEntity($eventData, []) — store_id берётся из события
        $store = Store::factory()->create(['code' => 'MSK_005']);
        $promotion = Promotion::factory()->create([
            'store_id' => $store->id,
            'source' => 'bagisto',
            'external_id' => 'promo_orphan',
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'merchant_sales',
            'entity_id' => null,
            'external_id' => 'promo_orphan',
            'source' => 'bagisto',
            'shop_code' => config('rabbitmq-events.shop_code'),
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $result = $this->consumer->processEvent($this->buildEventData([
            'event_type' => 'deleted',
            'id' => 'promo_orphan',
            'shop_id' => 'MSK_005',
            'shop_source' => 'bagisto',
        ]));

        $this->assertEquals('ack', $result);

        $this->assertSoftDeleted('promotions', ['id' => $promotion->id]);
    }

    public function test_store_not_found_returns_null(): void
    {
        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_id' => 'NON_EXISTENT_SHOP',
            'shop_source' => 'bagisto',
            'title' => 'Акция без магазина',
            'discount_percent' => 10,
        ]);

        $this->consumer->processEvent($eventData);

        $this->assertDatabaseMissing('promotions', [
            'title' => 'Акция без магазина',
        ]);
    }

    public function test_duplicate_event_is_skipped(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_005']);
        $eventId = Str::uuid()->toString();

        $eventData = $this->buildEventData([
            'event_id' => $eventId,
            'event_type' => 'created',
            'shop_id' => 'MSK_005',
            'shop_source' => 'bagisto',
            'title' => 'Уникальная акция',
            'discount_percent' => 10,
        ]);

        $result1 = $this->consumer->processEvent($eventData);
        $this->assertEquals('ack', $result1);

        $result2 = $this->consumer->processEvent($eventData);
        $this->assertEquals('ack', $result2);

        $this->assertEquals(1, Promotion::where('store_id', $store->id)->count());
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
            'id' => 'promo_'.Str::random(5),
            'source' => 'bagisto',
            'shop_id' => 'TEST_'.Str::random(3),
            'shop_source' => 'bagisto',
            'title' => 'Тестовая акция',
            'discount_percent' => 10,
            'starts_at' => now()->toIso8601String(),
            'ends_at' => now()->addWeek()->toIso8601String(),
            'is_active' => true,
        ], $overrides);
    }
}
