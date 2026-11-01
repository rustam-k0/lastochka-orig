<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\Store;
use App\Models\StoreProduct;
use App\RabbitMQ\Handlers\ProductBatchHandler;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Concerns\TogglesPriceTiersModule;
use Tests\TestCase;

final class ProductBatchPriceTiersTest extends TestCase
{
    use RefreshDatabase;
    use TogglesPriceTiersModule;

    private const LADDER = [
        ['min_quantity' => 2, 'price' => 249],
        ['min_quantity' => 5, 'price' => 199],
    ];

    /** Товар + metadata через штатный EventConsumer (обычный режим, магазин BT_001). */
    private function seedProduct(string $externalId, float $price): Product
    {
        $consumer = new EventConsumer('products', config('rabbitmq-events.entities.products'));
        $consumer->processEvent([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'created',
            'event_timestamp' => now()->subHour()->toIso8601String(),
            'id' => $externalId,
            'source' => '1c',
            'shop_code' => 'BT_001',
            'sku' => 'SKU-'.$externalId,
            'title' => 'Товар '.$externalId,
            'price' => $price,
            'active' => true,
        ]);

        return Product::where('sku', 'SKU-'.$externalId)->firstOrFail();
    }

    /** @param array<int, array<string, mixed>> $items */
    private function handle(array $items, string $shopCode = 'BT_001', ?string $eventId = null): string
    {
        return app(ProductBatchHandler::class)->handle([
            'event_id' => $eventId ?? (string) Str::uuid(),
            'event_timestamp' => now()->toIso8601String(),
            'event_type' => 'price_batch_updated',
            'event_source' => '1c',
            'source' => '1c',
            'shop_code' => $shopCode,
            'items' => $items,
        ]);
    }

    /** @return array<int, array{0: float, 1: float}> */
    private function ladderOf(Product $product, int $storeId): array
    {
        return ProductPriceTier::query()
            ->where('product_id', $product->id)
            ->where('store_id', $storeId)
            ->orderBy('min_quantity')
            ->get()
            ->map(fn (ProductPriceTier $tier) => [(float) $tier->min_quantity, (float) $tier->price])
            ->all();
    }

    private function tierWriteQueries(callable $callback): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $callback();
        $writes = collect(DB::getQueryLog())
            ->filter(fn (array $q) => preg_match('/^\s*(insert|delete)\b.+product_price_tiers/i', $q['query']) === 1)
            ->count();
        DB::disableQueryLog();

        return $writes;
    }

    public function test_batch_writes_ladders_and_applies_prices(): void
    {
        $this->setPriceTiersModule(true);
        $store = Store::factory()->create(['code' => 'BT_001']);
        $a = $this->seedProduct('ext-a', 100.00);
        $b = $this->seedProduct('ext-b', 200.00);

        $this->assertSame('ack', $this->handle([
            ['id' => 'ext-a', 'price' => 279, 'price_tiers' => self::LADDER],
            ['id' => 'ext-b', 'price' => 210],
        ]));

        $this->assertSame(279.0, (float) $a->fresh()->price);
        $this->assertSame(210.0, (float) $b->fresh()->price);
        $this->assertSame([[2.0, 249.0], [5.0, 199.0]], $this->ladderOf($a, $store->id));
        $this->assertSame([], $this->ladderOf($b, $store->id));
    }

    public function test_missing_key_keeps_ladder_and_empty_array_deletes_it(): void
    {
        $this->setPriceTiersModule(true);
        $store = Store::factory()->create(['code' => 'BT_001']);
        $keep = $this->seedProduct('ext-keep', 100.00);
        $clear = $this->seedProduct('ext-clear', 100.00);
        $this->seedPriceTiers($keep, $store->id, self::LADDER);
        $this->seedPriceTiers($clear, $store->id, self::LADDER);

        $this->handle([
            ['id' => 'ext-keep', 'price' => 120],
            ['id' => 'ext-clear', 'price' => 100, 'price_tiers' => []],
        ]);

        $this->assertCount(2, $this->ladderOf($keep, $store->id));
        $this->assertSame([], $this->ladderOf($clear, $store->id), 'лесенка очищена, хотя цена не менялась');
    }

    public function test_same_price_and_same_ladder_is_unchanged_noop(): void
    {
        $this->setPriceTiersModule(true);
        $store = Store::factory()->create(['code' => 'BT_001']);
        $product = $this->seedProduct('ext-same', 279.00);
        $this->seedPriceTiers($product, $store->id, self::LADDER);
        $updatedAt = $product->fresh()->updated_at;

        $eventId = (string) Str::uuid();
        $writes = $this->tierWriteQueries(fn () => $this->handle([
            ['id' => 'ext-same', 'price' => 279, 'price_tiers' => [
                ['min_quantity' => '5.000', 'price' => '199.00'],
                ['min_quantity' => 2, 'price' => 249],
            ]],
        ], eventId: $eventId));

        $this->assertSame(0, $writes, 'совпавшая лесенка не переписывается');
        $this->assertEquals($updatedAt, $product->fresh()->updated_at, 'позиция пропущена как unchanged');
        $this->assertSame($eventId, EventSyncMetadata::where('entity_type', 'products')
            ->where('external_id', 'ext-same')->value('last_event_id'));
    }

    public function test_same_price_with_changed_ladder_is_processed(): void
    {
        $this->setPriceTiersModule(true);
        $store = Store::factory()->create(['code' => 'BT_001']);
        $product = $this->seedProduct('ext-ladder', 279.00);
        $this->seedPriceTiers($product, $store->id, self::LADDER);

        $this->handle([
            ['id' => 'ext-ladder', 'price' => 279, 'price_tiers' => [['min_quantity' => 3, 'price' => 240]]],
        ]);

        $this->assertSame([[3.0, 240.0]], $this->ladderOf($product, $store->id));
    }

    public function test_broken_ladder_warns_and_price_is_applied(): void
    {
        $this->setPriceTiersModule(true);
        $store = Store::factory()->create(['code' => 'BT_001']);
        $product = $this->seedProduct('ext-broken', 100.00);
        $this->seedPriceTiers($product, $store->id, self::LADDER);

        Log::spy();

        $this->handle([
            ['id' => 'ext-broken', 'price' => 150, 'price_tiers' => [['min_quantity' => 0, 'price' => 10]]],
        ]);

        $this->assertSame(150.0, (float) $product->fresh()->price);
        $this->assertCount(2, $this->ladderOf($product, $store->id));
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'price_tiers'))
            ->once();
    }

    public function test_flag_off_ignores_key(): void
    {
        $this->setPriceTiersModule(false);
        Store::factory()->create(['code' => 'BT_001']);
        $product = $this->seedProduct('ext-off', 100.00);

        $queries = $this->priceTierTableQueries(
            fn () => $this->handle([['id' => 'ext-off', 'price' => 279, 'price_tiers' => self::LADDER]])
        );

        $this->assertSame(0, $queries, 'при выключенном флаге батч не читает и не пишет таблицу ступеней');
        $this->assertSame(279.0, (float) $product->fresh()->price);
        $this->assertSame(0, ProductPriceTier::count());
    }

    public function test_many_ladders_are_written_in_bounded_queries(): void
    {
        $this->setPriceTiersModule(true);
        Store::factory()->create(['code' => 'BT_001']);
        $items = [];
        foreach (range(1, 5) as $i) {
            $this->seedProduct('ext-bulk-'.$i, 100.00);
            $items[] = ['id' => 'ext-bulk-'.$i, 'price' => 100 + $i, 'price_tiers' => self::LADDER];
        }

        $writes = $this->tierWriteQueries(fn () => $this->handle($items));

        $this->assertSame(2, $writes, 'один DELETE и один INSERT на весь батч магазина');
        $this->assertSame(10, ProductPriceTier::count());
    }

    /**
     * Сетевой каталог + bulk-путь: простая пер-магазинная цена пишется массово
     * в store_products, лесенка: туда же, в магазин конверта.
     */
    public function test_network_bulk_path_writes_ladder_for_envelope_store(): void
    {
        $this->setPriceTiersModule(true, networkCatalog: true);
        config()->set('rabbitmq-events.bulk_price_batch', true);

        $anchor = Store::factory()->create(['code' => 'tiers-anchor']);
        $shop = Store::factory()->create(['code' => 'tiers-batch']);
        $product = Product::factory()->create(['store_id' => $anchor->id, 'price' => 0, 'quantity' => 0]);
        EventSyncMetadata::create([
            'entity_type' => 'products',
            'source' => '1c',
            'external_id' => 'ext-net',
            'shop_code' => null,
            'entity_id' => $product->id,
            'last_event_id' => (string) Str::uuid(),
            'last_event_timestamp' => now()->subMinute(),
        ]);

        $this->handle([['id' => 'ext-net', 'price' => 300, 'price_tiers' => self::LADDER]], shopCode: 'tiers-batch');

        $this->assertSame(300.0, (float) StoreProduct::where('store_id', $shop->id)->where('product_id', $product->id)->value('price'));
        $this->assertSame([[2.0, 249.0], [5.0, 199.0]], $this->ladderOf($product, $shop->id));
        $this->assertSame([], $this->ladderOf($product, $anchor->id));
    }

    /**
     * Финальное ревью (F4): счётчик отброшенных лесенок в сводке батча —
     * имя как в admin_back (tiers_dropped), инкремент при отбрасывании
     * битой лесенки позиции.
     */
    public function test_summary_counts_dropped_ladders(): void
    {
        $this->setPriceTiersModule(true);
        Store::factory()->create(['code' => 'BT_001']);
        $this->seedProduct('ext-tiers-ok', 100.00);
        $this->seedProduct('ext-tiers-broken', 100.00);

        Log::spy();

        $this->handle([
            ['id' => 'ext-tiers-ok', 'price' => 120, 'price_tiers' => self::LADDER],
            ['id' => 'ext-tiers-broken', 'price' => 150, 'price_tiers' => [['min_quantity' => 0, 'price' => 10]]],
        ]);

        Log::shouldHaveReceived('notice')
            ->withArgs(fn (string $message, array $context = []) => $message === 'Product batch processed'
                && ($context['tiers_dropped'] ?? -1) === 1)
            ->once();
    }

    /**
     * Финальное ревью (F5): existingLadders() выполнялся на каждом батче цен
     * при включённом флаге, даже если ни одна позиция пачки не несёт ключ
     * price_tiers. Батч без ключей ни у одной позиции не должен читать
     * таблицу ступеней вовсе.
     */
    public function test_batch_without_tier_keys_skips_existing_ladders_query(): void
    {
        $this->setPriceTiersModule(true);
        Store::factory()->create(['code' => 'BT_001']);
        $this->seedProduct('ext-no-tiers-a', 100.00);
        $this->seedProduct('ext-no-tiers-b', 100.00);

        $queries = $this->priceTierTableQueries(
            fn () => $this->handle([
                ['id' => 'ext-no-tiers-a', 'price' => 150],
                ['id' => 'ext-no-tiers-b', 'price' => 160],
            ])
        );

        $this->assertSame(0, $queries, 'батч без ключа price_tiers ни у одной позиции не должен читать таблицу ступеней');
    }

    // ==================== F14: ступень дороже обычной цены (батч) ====================

    public function test_batch_ladder_tier_above_regular_price_logs_warning(): void
    {
        $this->setPriceTiersModule(true);
        Store::factory()->create(['code' => 'BT_001']);
        $this->seedProduct('ext-tier-high', 100.00);

        Log::spy();

        $this->handle([
            ['id' => 'ext-tier-high', 'price' => 100, 'price_tiers' => [['min_quantity' => 1, 'price' => 150]]],
        ]);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => $message === 'price_tiers ladder has a tier above regular price')
            ->once();
    }

    public function test_batch_ladder_not_above_regular_price_has_no_warning(): void
    {
        $this->setPriceTiersModule(true);
        Store::factory()->create(['code' => 'BT_001']);
        $this->seedProduct('ext-tier-ok', 279.00);

        Log::spy();

        $this->handle([
            ['id' => 'ext-tier-ok', 'price' => 279, 'price_tiers' => self::LADDER],
        ]);

        Log::shouldNotHaveReceived('warning');
    }

    /**
     * Страж от ложного срабатывания на единицах: батч без доп. запросов не
     * умеет конвертировать сырую цену события в цену за порцию для
     * квантового товара, поэтому сознательно пропускает предупреждение для
     * таких позиций (финальное ревью допускает это явно).
     */
    public function test_batch_quantum_product_skips_regular_price_warning(): void
    {
        $this->setPriceTiersModule(true);
        Store::factory()->create(['code' => 'BT_001']);
        $product = $this->seedProduct('ext-tier-quantum', 100.00);
        $product->update(['quantity_step' => 200, 'measurement_unit' => 'gram', 'unit_type' => 'kg']);

        Log::spy();

        // Сырая цена события (100, без конвертации) заведомо ниже сырого
        // порога лесенки (450) — лобовое сравнение решило бы «ступень
        // дороже», но батч сознательно пропускает квантовые товары.
        $this->handle([
            ['id' => 'ext-tier-quantum', 'price' => 100, 'price_tiers' => [['min_quantity' => 1, 'price' => 450]]],
        ]);

        Log::shouldNotHaveReceived('warning');
    }
}
