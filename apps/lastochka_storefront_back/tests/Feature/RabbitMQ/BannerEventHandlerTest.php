<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BannerEventHandlerTest extends TestCase
{
    use RefreshDatabase;

    private EventConsumer $consumer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consumer = new EventConsumer('merchant_banners', config('rabbitmq-events.entities.merchant_banners'));

        Storage::fake('public');
    }

    public function test_banner_created_event_creates_banner(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);

        Http::fake([
            'https://cdn.example.com/*' => Http::response('fake-image-content', 200, ['Content-Type' => 'image/png']),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_id' => 'MSK_001',
            'shop_source' => 'bagisto',
            'title' => 'Главный баннер',
            'image_url' => 'https://cdn.example.com/banner.jpg',
            'link_type' => 'none',
            'is_active' => true,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $this->assertDatabaseHas('banners', [
            'store_id' => $store->id,
            'source' => 'bagisto',
            'title' => 'Главный баннер',
            'link_type' => 'none',
            'is_active' => true,
        ]);

        $banner = Banner::where('store_id', $store->id)->first();
        $this->assertNotNull($banner);
        $this->assertNotEmpty($banner->image_path);
    }

    public function test_banner_with_product_link(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_002']);
        $product = Product::factory()->create(['store_id' => $store->id]);

        Http::fake([
            'https://cdn.example.com/*' => Http::response('fake-image-content', 200, ['Content-Type' => 'image/png']),
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $product->id,
            'external_id' => 'prod_123',
            'source' => 'bagisto',
            'shop_code' => 'MSK_002',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_id' => 'MSK_002',
            'shop_source' => 'bagisto',
            'title' => 'Баннер с товаром',
            'image_url' => 'https://cdn.example.com/banner.jpg',
            'link_type' => 'product',
            'product_id' => 'prod_123',
            'product_source' => 'bagisto',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $banner = Banner::where('store_id', $store->id)->first();
        $this->assertNotNull($banner);
        $this->assertEquals('product', $banner->link_type->value);
        $this->assertEquals($product->id, $banner->link_product_id);
    }

    public function test_banner_with_category_link(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_003']);
        $category = Category::factory()->forStore($store)->create();

        Http::fake([
            'https://cdn.example.com/*' => Http::response('fake-image-content', 200, ['Content-Type' => 'image/png']),
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'categories',
            'entity_id' => $category->id,
            'external_id' => 'cat_123',
            'source' => 'bagisto',
            'shop_code' => 'MSK_003',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_id' => 'MSK_003',
            'shop_source' => 'bagisto',
            'title' => 'Баннер с категорией',
            'image_url' => 'https://cdn.example.com/banner.jpg',
            'link_type' => 'category',
            'category_id' => 'cat_123',
            'category_source' => 'bagisto',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $banner = Banner::where('store_id', $store->id)->first();
        $this->assertNotNull($banner);
        $this->assertEquals('category', $banner->link_type->value);
        $this->assertEquals($category->id, $banner->link_category_id);
    }

    public function test_banner_with_display_category(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_007']);
        $category = Category::factory()->forStore($store)->create();

        Http::fake([
            'https://cdn.example.com/*' => Http::response('fake-image-content', 200, ['Content-Type' => 'image/png']),
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'categories',
            'entity_id' => $category->id,
            'external_id' => 'cat_disp_1',
            'source' => 'bagisto',
            'shop_code' => 'MSK_007',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_id' => 'MSK_007',
            'shop_source' => 'bagisto',
            'title' => 'Баннер страницы категории',
            'image_url' => 'https://cdn.example.com/banner.jpg',
            'display_category_id' => 'cat_disp_1',
            'display_category_source' => 'bagisto',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $banner = Banner::where('store_id', $store->id)->first();
        $this->assertNotNull($banner);
        $this->assertEquals($category->id, $banner->display_category_id);
    }

    public function test_banner_update_clears_display_category(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_008']);
        $category = Category::factory()->forStore($store)->create();

        $banner = Banner::factory()->create([
            'store_id' => $store->id,
            'source' => 'bagisto',
            'external_id' => 'banner_disp_clear',
            'display_category_id' => $category->id,
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'merchant_banners',
            'entity_id' => $banner->id,
            'external_id' => 'banner_disp_clear',
            'source' => 'bagisto',
            'shop_code' => 'MSK_008',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'banner_disp_clear',
            'shop_id' => 'MSK_008',
            'shop_source' => 'bagisto',
            'title' => 'Баннер вернулся на главную',
            'display_category_id' => null,
            'display_category_source' => null,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $banner->refresh();
        $this->assertNull($banner->display_category_id);
    }

    public function test_banner_updated_event_updates_banner(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_004']);
        $banner = Banner::factory()->create([
            'store_id' => $store->id,
            'source' => 'bagisto',
            'external_id' => 'banner_update',
            'title' => 'Старый баннер',
            'is_active' => true,
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'merchant_banners',
            'entity_id' => $banner->id,
            'external_id' => 'banner_update',
            'source' => 'bagisto',
            'shop_code' => 'MSK_004',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'banner_update',
            'shop_id' => 'MSK_004',
            'shop_source' => 'bagisto',
            'title' => 'Новый баннер',
            'is_active' => false,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $banner->refresh();
        $this->assertEquals('Новый баннер', $banner->title);
        $this->assertFalse($banner->is_active);
    }

    public function test_banner_deleted_event_soft_deletes_banner(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_005']);
        $banner = Banner::factory()->create([
            'store_id' => $store->id,
            'source' => 'bagisto',
            'external_id' => 'banner_delete',
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'merchant_banners',
            'entity_id' => $banner->id,
            'external_id' => 'banner_delete',
            'source' => 'bagisto',
            'shop_code' => 'MSK_005',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'deleted',
            'id' => 'banner_delete',
            'shop_id' => 'MSK_005',
            'shop_source' => 'bagisto',
            'title' => 'Удаляемый баннер',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $this->assertSoftDeleted('banners', ['id' => $banner->id]);
    }

    public function test_store_not_found_returns_null(): void
    {
        Http::fake([
            'https://cdn.example.com/*' => Http::response('fake-image-content', 200, ['Content-Type' => 'image/png']),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_id' => 'NON_EXISTENT_SHOP',
            'shop_source' => 'bagisto',
            'title' => 'Баннер без магазина',
            'image_url' => 'https://cdn.example.com/banner.jpg',
        ]);

        $this->consumer->processEvent($eventData);

        $this->assertDatabaseMissing('banners', [
            'title' => 'Баннер без магазина',
        ]);
    }

    public function test_duplicate_event_is_skipped(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_006']);
        $eventId = Str::uuid()->toString();

        Http::fake([
            'https://cdn.example.com/*' => Http::response('fake-image-content', 200, ['Content-Type' => 'image/png']),
        ]);

        $eventData = $this->buildEventData([
            'event_id' => $eventId,
            'event_type' => 'created',
            'shop_id' => 'MSK_006',
            'shop_source' => 'bagisto',
            'title' => 'Уникальный баннер',
            'image_url' => 'https://cdn.example.com/banner.jpg',
        ]);

        $result1 = $this->consumer->processEvent($eventData);
        $this->assertEquals('ack', $result1);

        $result2 = $this->consumer->processEvent($eventData);
        $this->assertEquals('ack', $result2);

        $this->assertEquals(1, Banner::where('store_id', $store->id)->count());
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
            'id' => 'banner_'.Str::random(5),
            'source' => 'bagisto',
            'shop_id' => 'TEST_'.Str::random(3),
            'shop_source' => 'bagisto',
            'title' => 'Тестовый баннер',
            'link_type' => 'none',
            'is_active' => true,
        ], $overrides);
    }
}
