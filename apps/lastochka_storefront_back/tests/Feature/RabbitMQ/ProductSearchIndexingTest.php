<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Product;
use App\Models\Store;
use App\Services\Search\Contracts\SearchClientInterface;
use App\Services\Search\FakeSearchClient;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Регрессия: EventConsumer пакета comrades/laravel-rabbitmq-events сохраняет
 * модели через withoutEvents() → ProductObserver (Eloquent-события) не
 * срабатывает → товар попадает в БД, но НЕ в индекс Meilisearch. Поиск его
 * не находит, хотя в каталоге магазина он есть.
 *
 * Фикс: ProductEventTransformer индексирует явно в хуках afterCreate/
 * afterUpdate/afterDelete. Эти тесты гоняют реальный EventConsumer и
 * проверяют, что индекс синхронизирован с БД на каждом типе события.
 */
final class ProductSearchIndexingTest extends TestCase
{
    use RefreshDatabase;

    private EventConsumer $consumer;

    private FakeSearchClient $searchClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consumer = new EventConsumer('products', config('rabbitmq-events.entities.products'));

        $client = app(SearchClientInterface::class);
        $this->assertInstanceOf(FakeSearchClient::class, $client, 'В тестах должен использоваться FakeSearchClient');
        $this->searchClient = $client;
    }

    public function test_created_active_product_is_indexed(): void
    {
        Store::factory()->create(['code' => 'IDX_001']);

        $this->consumer->processEvent($this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'IDX_001',
            'sku' => 'IDX-MILK',
            'title' => 'Молоко индексируемое',
            'price' => 89.00,
            'active' => true,
        ]));

        $product = Product::where('sku', 'IDX-MILK')->firstOrFail();

        $this->assertTrue(
            $this->searchClient->hasDocument($product->id),
            'Активный товар после created-события должен попасть в индекс'
        );
        $this->assertSame('Молоко индексируемое', $this->searchClient->indexedDocument($product->id)['title']);
    }

    public function test_created_inactive_product_is_not_indexed(): void
    {
        Store::factory()->create(['code' => 'IDX_002']);

        $this->consumer->processEvent($this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'IDX_002',
            'sku' => 'IDX-HIDDEN',
            'title' => 'Неактивный товар',
            'price' => 50.00,
            'active' => false,
        ]));

        $product = Product::where('sku', 'IDX-HIDDEN')->firstOrFail();

        $this->assertFalse(
            $this->searchClient->hasDocument($product->id),
            'Неактивный товар не должен индексироваться'
        );
    }

    public function test_updated_product_is_reindexed_with_fresh_data(): void
    {
        $store = Store::factory()->create(['code' => 'IDX_003']);
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'sku' => 'IDX-UPD',
            'title' => 'Старое название',
            'price' => 100,
            'is_active' => true,
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $product->id,
            'external_id' => 'idx_upd',
            'source' => 'bagisto',
            'shop_code' => 'IDX_003',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $this->consumer->processEvent($this->buildEventData([
            'event_type' => 'updated',
            'id' => 'idx_upd',
            'shop_code' => 'IDX_003',
            'sku' => 'IDX-UPD',
            'title' => 'Новое название',
            'price' => 150.00,
            'active' => true,
        ]));

        $document = $this->searchClient->indexedDocument($product->id);
        $this->assertNotNull($document, 'Обновлённый товар должен быть в индексе');
        $this->assertSame('Новое название', $document['title']);
        $this->assertEquals(150, $document['price']);
    }

    public function test_deactivation_on_update_removes_from_index(): void
    {
        $store = Store::factory()->create(['code' => 'IDX_004']);
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'sku' => 'IDX-DEACT',
            'title' => 'Активный пока',
            'price' => 100,
            'is_active' => true,
        ]);

        // Сначала кладём в индекс, как будто товар уже искался
        $this->searchClient->indexDocument(['id' => $product->id, 'title' => $product->title]);
        $this->assertTrue($this->searchClient->hasDocument($product->id));

        EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $product->id,
            'external_id' => 'idx_deact',
            'source' => 'bagisto',
            'shop_code' => 'IDX_004',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $this->consumer->processEvent($this->buildEventData([
            'event_type' => 'updated',
            'id' => 'idx_deact',
            'shop_code' => 'IDX_004',
            'sku' => 'IDX-DEACT',
            'title' => 'Активный пока',
            'price' => 100.00,
            'active' => false,
        ]));

        $this->assertFalse(
            $this->searchClient->hasDocument($product->id),
            'Деактивированный товар должен быть удалён из индекса'
        );
    }

    public function test_deleted_event_removes_from_index(): void
    {
        $store = Store::factory()->create(['code' => 'IDX_005']);
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'sku' => 'IDX-DEL',
            'title' => 'Товар на удаление',
            'price' => 100,
            'is_active' => true,
        ]);

        $this->searchClient->indexDocument(['id' => $product->id, 'title' => $product->title]);
        $this->assertTrue($this->searchClient->hasDocument($product->id));

        EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $product->id,
            'external_id' => 'idx_del',
            'source' => 'bagisto',
            'shop_code' => 'IDX_005',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $this->consumer->processEvent($this->buildEventData([
            'event_type' => 'deleted',
            'id' => 'idx_del',
            'shop_code' => 'IDX_005',
            'sku' => 'IDX-DEL',
            'title' => 'Товар на удаление',
            'price' => 100.00,
        ]));

        $this->assertFalse(
            $this->searchClient->hasDocument($product->id),
            'Удалённый товар должен быть убран из индекса'
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildEventData(array $overrides = []): array
    {
        return array_merge([
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'created',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'prod_'.Str::random(5),
            'source' => 'bagisto',
            'shop_code' => 'TEST_'.Str::random(3),
            'sku' => 'SKU-'.Str::random(5),
            'title' => 'Тестовый товар',
            'price' => 100.00,
            'active' => true,
        ], $overrides);
    }
}
