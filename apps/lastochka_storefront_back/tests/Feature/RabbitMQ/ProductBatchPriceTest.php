<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Product;
use App\Models\Store;
use App\RabbitMQ\Handlers\ProductBatchHandler;
use App\Services\Search\Contracts\SearchClientInterface;
use App\Services\Search\FakeSearchClient;
use App\Services\Search\SearchService;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * product.price_batch_updated: bulk-резолв metadata, diff со значением в БД
 * (unchanged = no-op + metadata bump), изменённые позиции идут через
 * processPriceUpdatedEvent (инверсия discount_price), Meili — одной пачкой.
 */
final class ProductBatchPriceTest extends TestCase
{
    use RefreshDatabase;

    private FakeSearchClient $searchClient;

    protected function setUp(): void
    {
        parent::setUp();

        $client = app(SearchClientInterface::class);
        $this->assertInstanceOf(FakeSearchClient::class, $client);
        $this->searchClient = $client;
    }

    /**
     * Товар + metadata сеются через штатный EventConsumer (created от 1С) —
     * гарантирует корректную metadata без знания внутренностей пайплайна.
     * event_timestamp сида — час назад, чтобы батч (now) не отсекался
     * stale-guard'ом.
     */
    private function seedProduct(string $externalId, float $price, array $overrides = []): Product
    {
        $consumer = new EventConsumer('products', config('rabbitmq-events.entities.products'));
        $consumer->processEvent(array_merge([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'created',
            'event_timestamp' => now()->subHour()->toIso8601String(),
            'id' => $externalId,
            'source' => '1c',
            'shop_code' => 'BT_001',
            'sku' => 'SKU-' . $externalId,
            'title' => 'Товар ' . $externalId,
            'price' => $price,
            'active' => true,
        ], $overrides));

        return Product::where('sku', 'SKU-' . $externalId)->firstOrFail();
    }

    /**
     * Сид индексирует товары (afterCreate-хук трансформера) — сбрасываем
     * fake-индекс, чтобы ассертить только индексацию самого батча.
     */
    private function freshSearchClient(): void
    {
        $this->searchClient = new FakeSearchClient();
        $this->app->instance(SearchClientInterface::class, $this->searchClient);
        $this->app->forgetInstance(SearchService::class);
    }

    /** @param array<string, mixed> $overrides */
    private function handle(array $overrides = []): string
    {
        return app(ProductBatchHandler::class)->handle(array_merge([
            'event_id' => (string) Str::uuid(),
            'event_timestamp' => now()->toIso8601String(),
            'event_type' => 'price_batch_updated',
            'event_source' => '1c',
            'source' => '1c',
            'shop_code' => 'BT_001',
            'items' => [],
        ], $overrides));
    }

    private function metadataFor(string $externalId): EventSyncMetadata
    {
        return EventSyncMetadata::where('entity_type', 'products')
            ->where('source', '1c')
            ->where('shop_code', 'BT_001')
            ->where('external_id', $externalId)
            ->firstOrFail();
    }

    public function test_updates_changed_prices_and_bumps_metadata_for_all(): void
    {
        Store::factory()->create(['code' => 'BT_001']);
        $changed = $this->seedProduct('ext-a', 100.00);
        $same = $this->seedProduct('ext-b', 200.00);
        $this->freshSearchClient();

        $eventId = (string) Str::uuid();
        $result = $this->handle([
            'event_id' => $eventId,
            'items' => [
                ['id' => 'ext-a', 'sku' => 'SKU-ext-a', 'price' => 149.99, 'old_price' => 100.00],
                ['id' => 'ext-b', 'sku' => 'SKU-ext-b', 'price' => 200.00],
            ],
        ]);

        $this->assertSame('ack', $result);
        $this->assertEqualsWithDelta(149.99, (float) $changed->fresh()->price, 0.001);
        $this->assertEqualsWithDelta(200.00, (float) $same->fresh()->price, 0.001);

        // metadata bump у ОБЕИХ позиций (unchanged тоже подтверждается событием)
        $this->assertSame($eventId, $this->metadataFor('ext-a')->last_event_id);
        $this->assertSame($eventId, $this->metadataFor('ext-b')->last_event_id);

        // Meili: изменённый переиндексирован батчем, неизменённый не трогался
        $this->assertTrue($this->searchClient->hasDocument($changed->id));
        $this->assertFalse($this->searchClient->hasDocument($same->id));
    }

    public function test_discount_price_inversion_matches_single_event_semantics(): void
    {
        Store::factory()->create(['code' => 'BT_001']);
        $product = $this->seedProduct('ext-d', 329.00);
        $this->freshSearchClient();

        $this->handle([
            'items' => [
                ['id' => 'ext-d', 'sku' => 'SKU-ext-d', 'price' => 329.00, 'discount_price' => 259.00],
            ],
        ]);

        $fresh = $product->fresh();
        $this->assertEqualsWithDelta(259.00, (float) $fresh->price, 0.001);
        $this->assertEqualsWithDelta(329.00, (float) $fresh->price_old, 0.001);
    }

    public function test_missing_product_is_skipped_and_rest_processed(): void
    {
        Store::factory()->create(['code' => 'BT_001']);
        $known = $this->seedProduct('ext-a', 100.00);
        $this->freshSearchClient();

        $result = $this->handle([
            'items' => [
                ['id' => 'ext-ghost', 'sku' => 'SKU-ghost', 'price' => 1.00],
                ['id' => 'ext-a', 'sku' => 'SKU-ext-a', 'price' => 111.00],
            ],
        ]);

        $this->assertSame('ack', $result);
        $this->assertEqualsWithDelta(111.00, (float) $known->fresh()->price, 0.001);
        $this->assertSame(0, EventSyncMetadata::where('external_id', 'ext-ghost')->count());
    }

    public function test_stale_envelope_does_not_overwrite_fresh_data(): void
    {
        Store::factory()->create(['code' => 'BT_001']);
        $product = $this->seedProduct('ext-a', 100.00); // seed timestamp = -1 час
        $this->freshSearchClient();

        $result = $this->handle([
            'event_timestamp' => now()->subHours(2)->toIso8601String(), // старее сида
            'items' => [['id' => 'ext-a', 'sku' => 'SKU-ext-a', 'price' => 1.00]],
        ]);

        $this->assertSame('ack', $result);
        $this->assertEqualsWithDelta(100.00, (float) $product->fresh()->price, 0.001);
    }

    public function test_redelivery_of_same_message_is_noop(): void
    {
        Store::factory()->create(['code' => 'BT_001']);
        $product = $this->seedProduct('ext-a', 100.00);
        $this->freshSearchClient();

        $payload = [
            'event_id' => (string) Str::uuid(),
            'items' => [['id' => 'ext-a', 'sku' => 'SKU-ext-a', 'price' => 149.99]],
        ];

        $this->assertSame('ack', $this->handle($payload));
        $this->assertSame('ack', $this->handle($payload)); // повторная доставка

        $this->assertEqualsWithDelta(149.99, (float) $product->fresh()->price, 0.001);
    }

    public function test_broken_item_does_not_fail_batch(): void
    {
        Store::factory()->create(['code' => 'BT_001']);
        $a = $this->seedProduct('ext-a', 100.00);
        $b = $this->seedProduct('ext-b', 200.00);
        $this->freshSearchClient();

        $result = $this->handle([
            'items' => [
                ['id' => 'ext-a', 'sku' => 'SKU-ext-a'], // нет price
                ['id' => 'ext-b', 'sku' => 'SKU-ext-b', 'price' => 222.00],
            ],
        ]);

        $this->assertSame('ack', $result);
        $this->assertEqualsWithDelta(100.00, (float) $a->fresh()->price, 0.001);
        $this->assertEqualsWithDelta(222.00, (float) $b->fresh()->price, 0.001);
    }

    public function test_duplicate_ids_in_one_batch_first_wins(): void
    {
        Store::factory()->create(['code' => 'BT_001']);
        $product = $this->seedProduct('ext-a', 100.00);
        $this->freshSearchClient();

        $this->handle([
            'items' => [
                ['id' => 'ext-a', 'sku' => 'SKU-ext-a', 'price' => 111.00],
                ['id' => 'ext-a', 'sku' => 'SKU-ext-a', 'price' => 999.00],
            ],
        ]);

        $this->assertEqualsWithDelta(111.00, (float) $product->fresh()->price, 0.001);
    }

    public function test_empty_items_is_acked(): void
    {
        $this->assertSame('ack', $this->handle(['items' => []]));
    }
}
