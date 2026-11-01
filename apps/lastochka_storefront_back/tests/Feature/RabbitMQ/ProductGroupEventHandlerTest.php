<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductGroupItem;
use App\Models\Store;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProductGroupEventHandlerTest extends TestCase
{
    use RefreshDatabase;

    private EventConsumer $consumer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consumer = new EventConsumer('merchant_product_groups', config('rabbitmq-events.entities.merchant_product_groups'));

        Storage::fake('public');
    }

    public function test_product_group_created_event_creates_group(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);

        Http::fake([
            'https://cdn.example.com/*' => Http::response('fake-image-content', 200, ['Content-Type' => 'image/png']),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_id' => 'MSK_001',
            'shop_source' => 'bagisto',
            'title' => 'Хиты продаж',
            'image_url' => 'https://cdn.example.com/group.jpg',
            'show_on_main' => true,
            'show_on_merchant_page' => true,
            'show_in_merchant_catalog' => false,
            'is_active' => true,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $this->assertDatabaseHas('product_groups', [
            'store_id' => $store->id,
            'source' => 'bagisto',
            'title' => 'Хиты продаж',
            'show_on_home' => true,
            'show_on_store_page' => true,
            'show_in_catalog' => false,
            'is_active' => true,
        ]);

        $group = ProductGroup::where('store_id', $store->id)->first();
        $this->assertNotNull($group);
        $this->assertNotEmpty($group->image_path);
    }

    public function test_product_group_show_on_search_is_synced(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_SEARCH']);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_id' => 'MSK_SEARCH',
            'shop_source' => 'bagisto',
            'title' => 'Группа на поиске',
            'show_on_search' => true,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $this->assertDatabaseHas('product_groups', [
            'store_id' => $store->id,
            'title' => 'Группа на поиске',
            'show_on_search' => true,
        ]);
    }

    public function test_product_group_with_products_sync(): void
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
            'title' => 'Группа с товарами',
            'products' => [
                ['product_id' => 'prod_1', 'product_source' => 'bagisto'],
                ['product_id' => 'prod_2', 'product_source' => 'bagisto'],
            ],
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $group = ProductGroup::where('store_id', $store->id)->first();
        $this->assertNotNull($group);
        $this->assertCount(2, $group->items);

        // Проверяем позиции
        $this->assertEquals(0, $group->items->where('product_id', $product1->id)->first()->position);
        $this->assertEquals(1, $group->items->where('product_id', $product2->id)->first()->position);
    }

    public function test_product_group_items_replaced_on_update(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_003']);
        $product1 = Product::factory()->create(['store_id' => $store->id]);
        $product2 = Product::factory()->create(['store_id' => $store->id]);
        $product3 = Product::factory()->create(['store_id' => $store->id]);

        $group = ProductGroup::factory()->create([
            'store_id' => $store->id,
            'source' => 'bagisto',
            'external_id' => 'group_update',
        ]);

        ProductGroupItem::create(['product_group_id' => $group->id, 'product_id' => $product1->id, 'position' => 0]);
        ProductGroupItem::create(['product_group_id' => $group->id, 'product_id' => $product2->id, 'position' => 1]);

        EventSyncMetadata::create([
            'entity_type' => 'merchant_product_groups',
            'entity_id' => $group->id,
            'external_id' => 'group_update',
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
            'id' => 'group_update',
            'shop_id' => 'MSK_003',
            'shop_source' => 'bagisto',
            'title' => 'Обновлённая группа',
            'products' => [
                ['product_id' => 'prod_3', 'product_source' => 'bagisto'],
            ],
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $group->refresh();
        $this->assertCount(1, $group->items);
        $this->assertEquals($product3->id, $group->items->first()->product_id);
    }

    public function test_product_group_deleted_event_soft_deletes_group(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_004']);
        $group = ProductGroup::factory()->create([
            'store_id' => $store->id,
            'source' => 'bagisto',
            'external_id' => 'group_delete',
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'merchant_product_groups',
            'entity_id' => $group->id,
            'external_id' => 'group_delete',
            'source' => 'bagisto',
            'shop_code' => 'MSK_004',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'deleted',
            'id' => 'group_delete',
            'shop_id' => 'MSK_004',
            'shop_source' => 'bagisto',
            'title' => 'Удаляемая группа',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $this->assertSoftDeleted('product_groups', ['id' => $group->id]);
    }

    public function test_deleted_event_with_orphan_metadata_soft_deletes_group(): void
    {
        // Метаданные есть, но entity_id потерян: пакет ищет сущность через
        // findExistingEntity($eventData, []) — store_id берётся из события
        $store = Store::factory()->create(['code' => 'MSK_005']);
        $group = ProductGroup::factory()->create([
            'store_id' => $store->id,
            'source' => 'bagisto',
            'external_id' => 'group_orphan',
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'merchant_product_groups',
            'entity_id' => null,
            'external_id' => 'group_orphan',
            'source' => 'bagisto',
            'shop_code' => config('rabbitmq-events.shop_code'),
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $result = $this->consumer->processEvent($this->buildEventData([
            'event_type' => 'deleted',
            'id' => 'group_orphan',
            'shop_id' => 'MSK_005',
            'shop_source' => 'bagisto',
        ]));

        $this->assertEquals('ack', $result);

        $this->assertSoftDeleted('product_groups', ['id' => $group->id]);
    }

    public function test_product_group_is_complex_solution_is_synced(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_SOLUTION']);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_id' => 'MSK_SOLUTION',
            'shop_source' => 'bagisto',
            'title' => 'Комплексное решение',
            'is_complex_solution' => true,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $this->assertDatabaseHas('product_groups', [
            'store_id' => $store->id,
            'title' => 'Комплексное решение',
            'is_complex_solution' => true,
        ]);
    }

    public function test_event_without_is_complex_solution_key_keeps_local_value(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_SOLUTION_OLD']);
        $group = ProductGroup::factory()->complexSolution()->create([
            'store_id' => $store->id,
            'source' => 'bagisto',
            'external_id' => 'group_solution_old',
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'merchant_product_groups',
            'entity_id' => $group->id,
            'external_id' => 'group_solution_old',
            'source' => 'bagisto',
            'shop_code' => 'MSK_SOLUTION_OLD',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        // Событие от admin_back без ключа is_complex_solution (старый код)
        $eventData = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'group_solution_old',
            'shop_id' => 'MSK_SOLUTION_OLD',
            'shop_source' => 'bagisto',
            'title' => 'Обновление без ключа',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $this->assertDatabaseHas('product_groups', [
            'id' => $group->id,
            'title' => 'Обновление без ключа',
            'is_complex_solution' => true,
        ]);
    }

    public function test_store_not_found_returns_null(): void
    {
        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_id' => 'NON_EXISTENT_SHOP',
            'shop_source' => 'bagisto',
            'title' => 'Группа без магазина',
        ]);

        $this->consumer->processEvent($eventData);

        $this->assertDatabaseMissing('product_groups', [
            'title' => 'Группа без магазина',
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
            'title' => 'Уникальная группа',
        ]);

        $result1 = $this->consumer->processEvent($eventData);
        $this->assertEquals('ack', $result1);

        $result2 = $this->consumer->processEvent($eventData);
        $this->assertEquals('ack', $result2);

        $this->assertEquals(1, ProductGroup::where('store_id', $store->id)->count());
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
            'id' => 'group_'.Str::random(5),
            'source' => 'bagisto',
            'shop_id' => 'TEST_'.Str::random(3),
            'shop_source' => 'bagisto',
            'title' => 'Тестовая группа',
            'show_on_main' => false,
            'show_on_merchant_page' => false,
            'show_in_merchant_catalog' => false,
            'is_active' => true,
        ], $overrides);
    }
}
