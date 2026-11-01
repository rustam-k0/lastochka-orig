<?php

namespace Tests\Feature\RabbitMQ;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreProduct;
use App\Models\StorefrontSetting;
use App\RabbitMQ\Handlers\ProductBatchHandler;
use App\RabbitMQ\Support\NetworkCatalog;
use App\Services\Settings\ModuleConfigService;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Bulk-запись store_products для price/stock батчей в сетевом режиме:
 * простые позиции (без карточных полей кванта) пишутся через upsert,
 * эквивалентно per-item пути. Флаг rabbitmq-events.bulk_price_batch.
 */
class ProductBatchBulkTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->store = Store::factory()->create(['code' => 'batch-a']);
        $this->setNetworkCatalog(true);
        config()->set('rabbitmq-events.bulk_price_batch', true);
    }

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

    private function product(string $ext): Product
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'price' => 0,
            'price_old' => null,
            'quantity' => 0,
            'unit_type' => 'piece',
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'products',
            'source' => '1c',
            'external_id' => $ext,
            'shop_code' => null,
            'entity_id' => $product->id,
            'last_event_id' => (string) Str::uuid(),
            'last_event_timestamp' => now()->subMinute(),
        ]);

        return $product;
    }

    private function batch(string $eventType, array $items): array
    {
        return [
            'event_id' => (string) Str::uuid(),
            'event_timestamp' => now()->toIso8601String(),
            'event_type' => $eventType,
            'event_source' => '1c',
            'source' => '1c',
            'shop_code' => 'batch-a',
            'items' => $items,
        ];
    }

    private function handle(array $eventData): void
    {
        app(ProductBatchHandler::class)->handle($eventData);
    }

    private function storeRow(Product $p): ?StoreProduct
    {
        return StoreProduct::where('store_id', $this->store->id)
            ->where('product_id', $p->id)->first();
    }

    private function processOne(Product $p, string $ext, array $item, string $type, bool $bulk): void
    {
        config()->set('rabbitmq-events.bulk_price_batch', $bulk);
        $this->handle($this->batch($type, [array_merge(['id' => $ext], $item)]));
    }

    public function test_bulk_writes_store_prices_in_bounded_queries(): void
    {
        $items = [];
        for ($i = 0; $i < 5; $i++) {
            $ext = 'ext-p-' . $i . '-' . Str::random(4);
            $this->product($ext);
            $items[] = ['id' => $ext, 'price' => 100 + $i];
        }

        DB::enableQueryLog();
        $this->handle($this->batch('price_batch_updated', $items));
        $writes = collect(DB::getQueryLog())
            ->filter(fn ($q) => preg_match('/^\s*(insert|update)\b.+["`]?store_products["`]?/i', $q['query']))
            ->count();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(3, $writes, "store_products write-запросов: {$writes}, ожидали bulk");
    }

    public function test_bulk_price_matches_per_item(): void
    {
        $bulk = $this->product($eb = 'ext-eb-' . Str::random(5));
        $item = $this->product($ei = 'ext-ei-' . Str::random(5));

        $this->processOne($bulk, $eb, ['price' => 200, 'discount_price' => 159.5], 'price_batch_updated', true);
        $this->processOne($item, $ei, ['price' => 200, 'discount_price' => 159.5], 'price_batch_updated', false);

        $b = $this->storeRow($bulk);
        $this->assertEqualsWithDelta(159.5, (float) $b->price, 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $b->price_old, 0.001);
        $this->assertEqualsWithDelta((float) $this->storeRow($item)->price, (float) $b->price, 0.001);
        $this->assertEqualsWithDelta((float) $this->storeRow($item)->price_old, (float) $b->price_old, 0.001);
    }

    public function test_shadow_writes_per_item_and_logs_zero_divergence(): void
    {
        config()->set('rabbitmq-events.bulk_price_batch', false);
        config()->set('rabbitmq-events.bulk_price_batch_shadow', true);
        \Illuminate\Support\Facades\Log::spy();

        $p = $this->product($ext = 'ext-shw-' . Str::random(5));
        $this->handle($this->batch('price_batch_updated', [['id' => $ext, 'price' => 133]]));

        // Цена записана штатным (per-item) путём.
        $this->assertEqualsWithDelta(133.0, (float) $this->storeRow($p)->price, 0.001);

        // Shadow сверил bulk-результат с фактом и залогировал 0 расхождений.
        \Illuminate\Support\Facades\Log::shouldHaveReceived('notice')
            ->withArgs(fn ($message, $context = []) => $message === 'price_batch_shadow_compare'
                && ($context['compared'] ?? 0) > 0
                && ($context['diverged'] ?? -1) === 0)
            ->atLeast()->once();
    }

    public function test_bulk_stock_matches_per_item(): void
    {
        $bulk = $this->product($eb = 'ext-sb-' . Str::random(5));
        $item = $this->product($ei = 'ext-si-' . Str::random(5));

        $this->processOne($bulk, $eb, ['quantity' => 37], 'stock_batch_updated', true);
        $this->processOne($item, $ei, ['quantity' => 37], 'stock_batch_updated', false);

        $this->assertSame(37, (int) $this->storeRow($bulk)->quantity);
        $this->assertSame((int) $this->storeRow($item)->quantity, (int) $this->storeRow($bulk)->quantity);
    }
}
