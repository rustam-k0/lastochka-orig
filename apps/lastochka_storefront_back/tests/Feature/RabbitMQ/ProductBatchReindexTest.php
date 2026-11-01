<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Product;
use App\Models\Store;
use App\RabbitMQ\Handlers\ProductBatchHandler;
use App\Services\Search\Contracts\SearchClientInterface;
use App\Services\Search\FakeSearchClient;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * product.batch_updated: Meilisearch-индексация выполняется ОДНИМ bulk-вызовом
 * на сообщение, а не HTTP-вызовом на каждую позицию.
 *
 * Контекст: afterCreate/afterUpdate трансформера зовут reindexProduct() на
 * каждый товар. На полной выгрузке 1С (сотни позиций × тысячи сообщений)
 * per-item индексация делала Meili узким местом сервера. ProductBatchHandler
 * включает defer-режим трансформера на время батча и флашит накопленные id
 * одним indexProducts() после цикла.
 */
final class ProductBatchReindexTest extends TestCase
{
    use RefreshDatabase;

    private FakeSearchClient $searchClient;

    protected function setUp(): void
    {
        parent::setUp();

        $client = app(SearchClientInterface::class);
        $this->assertInstanceOf(FakeSearchClient::class, $client, 'В тестах должен использоваться FakeSearchClient');
        $this->searchClient = $client;
    }

    /** @param array<string, mixed> $overrides */
    private function handle(array $overrides = []): string
    {
        return app(ProductBatchHandler::class)->handle(array_merge([
            'event_id' => (string) Str::uuid(),
            'event_timestamp' => now()->toIso8601String(),
            'event_type' => 'batch_updated',
            'event_source' => '1c',
            'source' => '1c',
            'shop_code' => 'BRX_001',
            'items' => [],
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed> */
    private function item(string $externalId, array $overrides = []): array
    {
        return array_merge([
            'id' => $externalId,
            'sku' => 'SKU-' . $externalId,
            'title' => 'Товар ' . $externalId,
            'price' => 100.00,
            'active' => true,
        ], $overrides);
    }

    public function test_batch_upsert_indexes_all_products_in_single_bulk_call(): void
    {
        Store::factory()->create(['code' => 'BRX_001']);

        $result = $this->handle([
            'items' => [
                $this->item('ext-1'),
                $this->item('ext-2'),
                $this->item('ext-3'),
            ],
        ]);

        $this->assertSame('ack', $result);

        $products = Product::whereIn('sku', ['SKU-ext-1', 'SKU-ext-2', 'SKU-ext-3'])->get();
        $this->assertCount(3, $products);

        foreach ($products as $product) {
            $this->assertTrue(
                $this->searchClient->hasDocument($product->id),
                "Товар {$product->sku} должен попасть в поисковый индекс."
            );
        }

        $this->assertSame(
            1,
            $this->searchClient->indexDocumentsCalls,
            'Весь батч индексируется одним bulk-вызовом.'
        );
        $this->assertSame(
            0,
            $this->searchClient->indexDocumentCalls,
            'Per-item индексация в батче отключена defer-режимом.'
        );
    }

    public function test_defer_mode_is_reset_after_batch(): void
    {
        Store::factory()->create(['code' => 'BRX_001']);

        $this->handle(['items' => [$this->item('ext-batch')]]);

        // Одиночное событие после батча индексируется немедленно (per-item):
        // defer-режим не должен «залипнуть» включённым.
        (new EventConsumer('products', config('rabbitmq-events.entities.products')))
            ->processEvent([
                'event_id' => (string) Str::uuid(),
                'event_type' => 'created',
                'event_timestamp' => now()->toIso8601String(),
                'id' => 'ext-single',
                'source' => '1c',
                'shop_code' => 'BRX_001',
                'sku' => 'SKU-ext-single',
                'title' => 'Одиночный товар',
                'price' => 55.00,
                'active' => true,
            ]);

        $single = Product::where('sku', 'SKU-ext-single')->firstOrFail();

        $this->assertTrue($this->searchClient->hasDocument($single->id));
        $this->assertGreaterThan(
            0,
            $this->searchClient->indexDocumentCalls,
            'После батча одиночные события снова индексируются per-item.'
        );
    }

    public function test_batch_item_error_still_flushes_bulk_reindex(): void
    {
        Store::factory()->create(['code' => 'BRX_001']);

        $result = $this->handle([
            'items' => [
                $this->item('ext-ok'),
                // Битая позиция: нет title/price → validate() пайплайна кидает
                // исключение, позиция уходит в errors, батч продолжается.
                ['id' => 'ext-broken', 'sku' => 'SKU-ext-broken'],
            ],
        ]);

        $this->assertSame('ack', $result);

        $ok = Product::where('sku', 'SKU-ext-ok')->firstOrFail();
        $this->assertTrue(
            $this->searchClient->hasDocument($ok->id),
            'Валидные позиции индексируются даже когда соседняя позиция битая (flush в finally).'
        );
        $this->assertSame(1, $this->searchClient->indexDocumentsCalls);
    }

    public function test_soft_deleted_in_batch_is_removed_from_index(): void
    {
        Store::factory()->create(['code' => 'BRX_001']);

        // Сид: товар создан и проиндексирован.
        $this->handle(['items' => [$this->item('ext-del')]]);
        $product = Product::where('sku', 'SKU-ext-del')->firstOrFail();
        $this->assertTrue($this->searchClient->hasDocument($product->id));

        // Следующий батч помечает его удалённым (DelProd из 1С).
        $this->handle([
            'items' => [
                $this->item('ext-del', ['deleted_at' => now()->toIso8601String()]),
            ],
        ]);

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertFalse(
            $this->searchClient->hasDocument($product->id),
            'Soft-deleted в батче товар должен быть удалён из поискового индекса при flush.'
        );
    }
}
