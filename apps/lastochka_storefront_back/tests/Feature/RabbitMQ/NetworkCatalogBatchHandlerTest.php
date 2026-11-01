<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Enums\MeasurementUnit;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\StoreProduct;
use App\RabbitMQ\Handlers\ProductBatchHandler;
use App\RabbitMQ\Support\NetworkCatalog;
use App\Services\Search\Contracts\SearchClientInterface;
use App\Services\Search\FakeSearchClient;
use App\Services\Settings\ModuleConfigService;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Батч-события товаров (price_batch_updated / stock_batch_updated /
 * batch_updated) в режиме сетевого каталога:
 *  - metadata карточки резолвится глобально, без фильтра по shop_code;
 *  - цены/остатки батча ложатся в store_products магазина конверта;
 *  - diff-оптимизация сравнивает со строкой store_products, а не с
 *    глобальными колонками products (там цены/остатка магазина нет);
 *  - карточные поля кванта (quantity_step/measurement_unit) diff не отсекает.
 */
final class NetworkCatalogBatchHandlerTest extends TestCase
{
    use RefreshDatabase;

    private Store $anchorStore;

    private Store $storeA;

    private Product $product;

    private FakeSearchClient $searchClient;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $client = app(SearchClientInterface::class);
        $this->assertInstanceOf(FakeSearchClient::class, $client);
        $this->searchClient = $client;

        $this->anchorStore = Store::factory()->create(['code' => 'anchor']);
        $this->storeA = Store::factory()->create(['code' => 'batch-a']);

        $this->setNetworkCatalog(true);

        $this->product = Product::factory()->create([
            'store_id' => $this->anchorStore->id,
            'price' => 0,
            'price_old' => null,
            'quantity' => 0,
            'unit_type' => 'piece',
        ]);

        // Metadata сетевой карточки: создана событием БЕЗ shop_code —
        // главный кейс: price/stock-батч магазина обязан найти товар глобально.
        EventSyncMetadata::create([
            'entity_type' => 'products',
            'source' => '1c',
            'external_id' => 'guid-sf-batch',
            'shop_code' => null,
            'entity_id' => $this->product->id,
            'last_event_id' => (string) Str::uuid(),
            'last_event_timestamp' => now()->subMinute(),
        ]);
    }

    /**
     * Флаг модуля хранится в storefront_settings.project_modules (type=json).
     * ModuleConfigService — singleton с мемоизацией, поэтому после записи
     * сбрасываем и кеш, и мемо. global_scope сущности products в проде
     * выставляет AppServiceProvider на boot — в тесте флаг появляется позже,
     * поэтому применяем вручную.
     */
    private function setNetworkCatalog(bool $enabled): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    'network_catalog' => ['label' => 'Сетевой каталог', 'enabled' => $enabled],
                ], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ]
        );

        app(ModuleConfigService::class)->invalidateCache();
        NetworkCatalog::applyGlobalScopeConfig();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function batch(string $eventType, array $items, array $overrides = []): array
    {
        return array_merge([
            'event_id' => (string) Str::uuid(),
            'event_timestamp' => now()->toIso8601String(),
            'event_type' => $eventType,
            'event_source' => '1c',
            'source' => '1c',
            'shop_code' => 'batch-a',
            'items' => $items,
        ], $overrides);
    }

    private function storeRow(): ?StoreProduct
    {
        return StoreProduct::where('store_id', $this->storeA->id)
            ->where('product_id', $this->product->id)
            ->first();
    }

    // =====================================================================
    // Глобальный резолв + запись в store_products
    // =====================================================================

    public function test_price_batch_upserts_store_products(): void
    {
        $result = app(ProductBatchHandler::class)->handle($this->batch('price_batch_updated', [
            ['id' => 'guid-sf-batch', 'sku' => 'B-1', 'price' => 250.0, 'discount_price' => 199.0],
        ]));

        $this->assertSame('ack', $result);

        $row = $this->storeRow();
        $this->assertNotNull($row, 'товар должен резолвиться глобально, без фильтра shop_code');
        $this->assertEquals(199.0, (float) $row->price, 'инверсия: клиент платит discount_price');
        $this->assertEquals(250.0, (float) $row->price_old);
        $this->assertEquals(0.0, (float) $this->product->fresh()->price, 'глобальную колонку не трогаем');
    }

    public function test_stock_batch_upserts_store_products(): void
    {
        $result = app(ProductBatchHandler::class)->handle($this->batch('stock_batch_updated', [
            ['id' => 'guid-sf-batch', 'sku' => 'B-1', 'quantity' => 33],
        ]));

        $this->assertSame('ack', $result);
        $this->assertSame(33, (int) $this->storeRow()?->quantity);
        $this->assertSame(0, (int) $this->product->fresh()->quantity, 'глобальную колонку не трогаем');
    }

    // =====================================================================
    // Diff-оптимизация: база сравнения — store_products, а не products
    // =====================================================================

    public function test_price_diff_compares_store_row_not_product_column(): void
    {
        // Совпадение с глобальной колонкой products не должно считаться «unchanged»:
        // цена магазина живёт в store_products, там строки ещё нет.
        $this->product->update(['price' => 199.0, 'price_old' => 250.0]);

        app(ProductBatchHandler::class)->handle($this->batch('price_batch_updated', [
            ['id' => 'guid-sf-batch', 'sku' => 'B-1', 'price' => 250.0, 'discount_price' => 199.0],
        ]));

        $row = $this->storeRow();
        $this->assertNotNull($row, 'нет строки магазина — позиция обязана обработаться');
        $this->assertEquals(199.0, (float) $row->price);
    }

    public function test_price_diff_skips_position_when_store_row_matches(): void
    {
        StoreProduct::create([
            'store_id' => $this->storeA->id,
            'product_id' => $this->product->id,
            'price' => 199.0,
            'price_old' => 250.0,
        ]);

        app(ProductBatchHandler::class)->handle($this->batch('price_batch_updated', [
            ['id' => 'guid-sf-batch', 'sku' => 'B-1', 'price' => 250.0, 'discount_price' => 199.0],
        ]));

        $this->assertNull($this->storeRow()?->synced_at, 'цена совпала — no-op, upsert не выполняется');
    }

    public function test_stock_diff_compares_store_row_not_product_column(): void
    {
        $this->product->update(['quantity' => 33]);

        app(ProductBatchHandler::class)->handle($this->batch('stock_batch_updated', [
            ['id' => 'guid-sf-batch', 'sku' => 'B-1', 'quantity' => 33],
        ]));

        $this->assertSame(33, (int) $this->storeRow()?->quantity,
            'нет строки магазина — позиция обязана обработаться');
    }

    public function test_stock_diff_skips_position_when_store_row_matches(): void
    {
        StoreProduct::create([
            'store_id' => $this->storeA->id,
            'product_id' => $this->product->id,
            'quantity' => 33,
        ]);

        app(ProductBatchHandler::class)->handle($this->batch('stock_batch_updated', [
            ['id' => 'guid-sf-batch', 'sku' => 'B-1', 'quantity' => 33],
        ]));

        $this->assertNull($this->storeRow()?->synced_at, 'остаток совпал — no-op, upsert не выполняется');
    }

    public function test_weight_product_stock_diff_uses_grams(): void
    {
        $this->product->update(['unit_type' => 'kg']);

        StoreProduct::create([
            'store_id' => $this->storeA->id,
            'product_id' => $this->product->id,
            'quantity' => 3000,
        ]);

        app(ProductBatchHandler::class)->handle($this->batch('stock_batch_updated', [
            ['id' => 'guid-sf-batch', 'sku' => 'B-1', 'quantity' => 3],
        ]));

        $this->assertNull($this->storeRow()?->synced_at, '3 кг = 3000 г — остаток не изменился');
    }

    // =====================================================================
    // Карточные поля кванта не теряются на неизменившейся цене
    // =====================================================================

    public function test_card_fields_applied_even_when_price_unchanged(): void
    {
        $this->product->update([
            'unit_type' => 'kg',
            'quantity_step' => 100,
            'measurement_unit' => 'piece',
        ]);

        StoreProduct::create([
            'store_id' => $this->storeA->id,
            'product_id' => $this->product->id,
            'price' => 120.5,
            'price_old' => null,
        ]);

        app(ProductBatchHandler::class)->handle($this->batch('price_batch_updated', [
            [
                'id' => 'guid-sf-batch',
                'sku' => 'B-1',
                'price' => 120.5,
                'quantity_step' => 250,
                'measurement_unit' => 'gram',
                'unit_type' => 'kg',
            ],
        ]));

        $fresh = $this->product->fresh();
        $this->assertSame(250, (int) $fresh->quantity_step,
            'цена не изменилась, но шаг продажи — карточное поле, diff его отсекать не должен');
        $this->assertSame(MeasurementUnit::Gram->value, $fresh->measurement_unit->value);
    }

    // =====================================================================
    // Meili: price/stock-батчи в сетевом режиме документы не переиндексируют
    // =====================================================================

    public function test_price_and_stock_batches_do_not_reindex_in_network_mode(): void
    {
        // Товар попал в индекс на factory-create через ProductObserver —
        // чистим, чтобы проверять именно переиндексацию из батча.
        $this->searchClient->resetIndex();

        app(ProductBatchHandler::class)->handle($this->batch('price_batch_updated', [
            ['id' => 'guid-sf-batch', 'sku' => 'B-1', 'price' => 250.0],
        ]));

        app(ProductBatchHandler::class)->handle($this->batch('stock_batch_updated', [
            ['id' => 'guid-sf-batch', 'sku' => 'B-1', 'quantity' => 5],
        ]));

        $this->assertFalse($this->searchClient->hasDocument($this->product->id),
            'цена/остаток в сетевом режиме пер-магазинные, документ от них не меняется');
    }

    public function test_price_batch_reindexes_position_with_card_fields(): void
    {
        $this->searchClient->resetIndex();

        app(ProductBatchHandler::class)->handle($this->batch('price_batch_updated', [
            ['id' => 'guid-sf-batch', 'sku' => 'B-1', 'price' => 250.0, 'quantity_step' => 500],
        ]));

        $this->assertTrue($this->searchClient->hasDocument($this->product->id),
            'quantity_step — глобальное поле карточки, участвует в in_stock документа');
    }

    // =====================================================================
    // Батч карточек без shop_code в конверте
    // =====================================================================

    public function test_card_batch_without_shop_code_is_processed(): void
    {
        $result = app(ProductBatchHandler::class)->handle($this->batch('batch_updated', [
            ['id' => 'guid-sf-batch', 'sku' => 'B-1', 'title' => 'Вино Разгуляйка белое'],
        ], ['shop_code' => null]));

        $this->assertSame('ack', $result);
        $this->assertSame('Вино Разгуляйка белое', $this->product->fresh()->title);
    }

    // =====================================================================
    // Легаси-режим: база сравнения остаётся products
    // =====================================================================

    public function test_legacy_mode_still_compares_product_columns(): void
    {
        $this->setNetworkCatalog(false);

        EventSyncMetadata::where('external_id', 'guid-sf-batch')->update(['shop_code' => 'batch-a']);
        $this->product->update(['store_id' => $this->storeA->id, 'price' => 10.0]);

        app(ProductBatchHandler::class)->handle($this->batch('price_batch_updated', [
            ['id' => 'guid-sf-batch', 'sku' => 'B-1', 'price' => 77.0],
        ]));

        $this->assertEquals(77.0, (float) $this->product->fresh()->price, 'легаси: пишем в колонку products');
        $this->assertSame(0, StoreProduct::count());
    }
}
