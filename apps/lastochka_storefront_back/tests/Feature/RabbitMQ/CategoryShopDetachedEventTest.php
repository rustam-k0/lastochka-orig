<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Category;
use App\Models\Store;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Покрывает прием custom RabbitMQ-события `category.shop_detached`
 * из admin_back (ALADDIN-9822). Сценарий: админ отвязывает категорию
 * от магазина в курьерке — storefront должен удалить pivot-запись
 * category_store для этой пары.
 */
final class CategoryShopDetachedEventTest extends TestCase
{
    use RefreshDatabase;

    private EventConsumer $consumer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consumer = new EventConsumer(
            'categories',
            config('rabbitmq-events.entities.categories')
        );
    }

    public function test_shop_detached_event_removes_single_pivot_row(): void
    {
        $storeAladdin = Store::factory()->create(['code' => 'aladdinmarketplace']);
        $storeOrus = Store::factory()->create(['code' => 'orus']);

        $category = Category::factory()->create(['name' => 'Колбасы']);
        $category->stores()->syncWithoutDetaching([
            $storeAladdin->id => ['is_active' => true, 'position' => 0],
            $storeOrus->id => ['is_active' => true, 'position' => 0],
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'categories',
            'entity_id' => $category->id,
            'external_id' => 'cat_kolbasy',
            'source' => 'admin',
            'shop_code' => null, // global_scope
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'shop_detached',
            'id' => 'cat_kolbasy',
            'shop_code' => 'aladdinmarketplace',
            'title' => 'Колбасы',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertSame('ack', $result);

        $category->refresh();
        $storeIds = $category->stores()->pluck('stores.id')->all();

        $this->assertNotContains($storeAladdin->id, $storeIds, 'Pivot row for Aladdin must be removed');
        $this->assertContains($storeOrus->id, $storeIds, 'Pivot row for Orus must remain untouched');
    }

    public function test_shop_detached_without_store_is_noop(): void
    {
        $category = Category::factory()->create(['name' => 'Сироты']);

        EventSyncMetadata::create([
            'entity_type' => 'categories',
            'entity_id' => $category->id,
            'external_id' => 'cat_orphan',
            'source' => 'admin',
            'shop_code' => null,
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'shop_detached',
            'id' => 'cat_orphan',
            'shop_code' => 'ghost_shop_code',
            'title' => 'Сироты',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertSame('ack', $result);
        // Не падает, категория жива
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_shop_detached_event_for_unknown_category_is_skipped(): void
    {
        Store::factory()->create(['code' => 'aladdinmarketplace']);

        $eventData = $this->buildEventData([
            'event_type' => 'shop_detached',
            'id' => 'cat_never_synced',
            'shop_code' => 'aladdinmarketplace',
            'title' => 'Никогда не существовавшая',
        ]);

        // Категории не существует, metadata нет — consumer должен ACK'нуть без падения.
        $result = $this->consumer->processEvent($eventData);

        $this->assertSame('ack', $result);
        $this->assertDatabaseMissing('categories', ['name' => 'Никогда не существовавшая']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildEventData(array $overrides = []): array
    {
        return array_merge([
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'shop_detached',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'cat_'.Str::random(5),
            'source' => 'admin',
            'shop_code' => 'TEST_'.Str::random(3),
            'title' => 'Тестовая категория',
        ], $overrides);
    }
}
