<?php

declare(strict_types=1);

namespace Tests\Feature\PriceTiers;

use App\Models\Price;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Type;
use App\NetworkCatalog\Support\NetworkCatalogMode;
use App\RabbitMQ\Handlers\ProductBatchHandler;
use App\Services\ModuleConfigService;
use App\Services\Pricing\PriceTierLadder;
use App\Services\ProductShopPrice\ProductShopPriceService;
use App\Services\Quantum\CategoryQuantumService;
use App\Transformers\ProductEventTransformer;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithPriceTiers;
use Tests\TestCase;

/**
 * product.price_batch_updated с ключом price_tiers в позициях (MID-308):
 * лесенки магазина подгружаются одним запросом, совпавшая позиция
 * пропускается, изменённые лесенки пишет сам батч (и для bulk-, и для
 * поштучных позиций), при выключенном флаге ключ игнорируется.
 */
class PriceTiersBatchTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithPriceTiers;

    private Shop $shop;
    private ProductShopPriceService $tiers;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        config()->set('rabbitmq-events.enabled', false);
        config()->set('rabbitmq-events.bulk_price_batch', false);
        config()->set('rabbitmq-events.bulk_price_batch_shadow', false);
        Cache::forever(NetworkCatalogMode::CACHE_KEY, false);

        $this->shop = Shop::create(['title' => 'Батч-магазин лесенок', 'code' => 'BTT_' . Str::random(6)]);
        $this->tiers = app(ProductShopPriceService::class);

        $this->setPriceTiersModule(true);
    }

    protected function tearDown(): void
    {
        Cache::forget(NetworkCatalogMode::CACHE_KEY);
        ModuleConfigService::invalidateCache();
        Model::reguard();
        parent::tearDown();
    }

    private function createProduct(string $externalId, ?float $priceValue = null): Product
    {
        $product = Product::create([
            'title' => 'Товар ' . $externalId,
            'articul' => 'ART-' . $externalId . '-' . Str::random(4),
            'unit_type_id' => Type::UNIT_PIECE,
            'status_id' => 1,
            'info_weight_type_id' => Type::INFO_WEIGHT_PIECE,
            'base_unit_type_id' => Type::INFO_WEIGHT_PIECE,
            'shop_id' => $this->shop->id,
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $product->id,
            'source' => '1c',
            'external_id' => $externalId,
            'shop_code' => $this->shop->code,
            'last_event_id' => (string) Str::uuid(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        if ($priceValue !== null) {
            Price::create([
                'product_id' => $product->id,
                'shop_id' => $this->shop->id,
                'type_id' => Type::PRICE_DEFAULT,
                'value' => $priceValue,
                'step' => 1,
            ]);
        }

        return $product;
    }

    private function handle(array $items, array $overrides = []): string
    {
        return app(ProductBatchHandler::class)->handle(array_merge([
            'event_id' => (string) Str::uuid(),
            'event_timestamp' => now()->toIso8601String(),
            'event_type' => 'price_batch_updated',
            'event_source' => '1c',
            'source' => '1c',
            'shop_code' => $this->shop->code,
            'items' => $items,
        ], $overrides));
    }

    private function priceValue(Product $product): float
    {
        return (float) Price::where('product_id', $product->id)
            ->where('shop_id', $this->shop->id)
            ->where('type_id', Type::PRICE_DEFAULT)
            ->value('value');
    }

    private function seedLadder(Product $product, array $raw): void
    {
        $this->tiers->syncPriceTiers($product->id, $this->shop->id, PriceTierLadder::normalize($raw));
    }

    /** @return array<int, int> */
    private function tierIds(Product $product): array
    {
        return DB::table('price_tiers')->where('product_id', $product->id)->orderBy('id')->pluck('id')->all();
    }

    public function test_per_item_position_gets_price_and_ladder(): void
    {
        $product = $this->createProduct('pt-a', 100.00);

        $this->assertSame('ack', $this->handle([
            ['id' => 'pt-a', 'price' => 279.00, 'price_tiers' => [
                ['min_quantity' => 5, 'price' => 199],
                ['min_quantity' => 2, 'price' => 249],
            ]],
        ]));

        $this->assertEqualsWithDelta(279.0, $this->priceValue($product), 0.001);
        $this->assertSame([
            ['min_quantity' => 2.0, 'price' => 249.0],
            ['min_quantity' => 5.0, 'price' => 199.0],
        ], $this->tiers->ladderFor($product->id, $this->shop->id));
    }

    public function test_same_price_and_same_ladder_is_skipped(): void
    {
        $product = $this->createProduct('pt-same', 279.00);
        $this->seedLadder($product, [['min_quantity' => 2, 'price' => 249]]);
        $idsBefore = $this->tierIds($product);

        // Совпавшая позиция не должна доходить до квант-пересчёта.
        $this->mock(CategoryQuantumService::class)->shouldNotReceive('applyToProduct');

        $eventId = (string) Str::uuid();
        $this->handle([
            ['id' => 'pt-same', 'price' => 279.00, 'price_tiers' => [['min_quantity' => '2.000', 'price' => '249.00']]],
        ], ['event_id' => $eventId]);

        $this->assertSame($idsBefore, $this->tierIds($product), 'совпавшая лесенка не переписывается');
        $this->assertSame($eventId, EventSyncMetadata::where('entity_id', $product->id)
            ->where('entity_type', 'products')->value('last_event_id'));
    }

    public function test_same_price_with_changed_ladder_writes_only_ladder(): void
    {
        $product = $this->createProduct('pt-tiers-only', 279.00);
        $this->seedLadder($product, [['min_quantity' => 2, 'price' => 249]]);

        $this->mock(CategoryQuantumService::class)->shouldNotReceive('applyToProduct');

        $this->handle([
            ['id' => 'pt-tiers-only', 'price' => 279.00, 'price_tiers' => [['min_quantity' => 3, 'price' => 239]]],
        ]);

        $this->assertSame(
            [['min_quantity' => 3.0, 'price' => 239.0]],
            $this->tiers->ladderFor($product->id, $this->shop->id)
        );
    }

    public function test_position_without_key_keeps_ladder_and_empty_array_clears_it(): void
    {
        $keeps = $this->createProduct('pt-keep', 100.00);
        $clears = $this->createProduct('pt-clear', 100.00);
        $this->seedLadder($keeps, [['min_quantity' => 2, 'price' => 90]]);
        $this->seedLadder($clears, [['min_quantity' => 2, 'price' => 90]]);

        $this->handle([
            ['id' => 'pt-keep', 'price' => 110.00],
            ['id' => 'pt-clear', 'price' => 100.00, 'price_tiers' => []],
        ]);

        $this->assertCount(1, $this->tiers->ladderFor($keeps->id, $this->shop->id));
        $this->assertSame([], $this->tiers->ladderFor($clears->id, $this->shop->id));
        $this->assertEqualsWithDelta(110.0, $this->priceValue($keeps), 0.001);
    }

    public function test_broken_ladder_is_dropped_but_price_applies(): void
    {
        $product = $this->createProduct('pt-broken', 100.00);
        $this->seedLadder($product, [['min_quantity' => 2, 'price' => 90]]);
        Log::spy();

        $this->handle([
            ['id' => 'pt-broken', 'price' => 150.00, 'price_tiers' => [['min_quantity' => 0, 'price' => 90]]],
        ]);

        $this->assertEqualsWithDelta(150.0, $this->priceValue($product), 0.001);
        $this->assertSame(
            [['min_quantity' => 2.0, 'price' => 90.0]],
            $this->tiers->ladderFor($product->id, $this->shop->id)
        );
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => $message === 'price_tiers dropped: invalid ladder, price applied without tiers')
            ->once();

        // MID-308 (F2 финального ревью): отброшенная лесенка видна в сводной
        // статистике батча, а не только в per-item warning.
        Log::shouldHaveReceived('notice')
            ->withArgs(fn ($message, array $context) => $message === 'Product batch processed'
                && ($context['tiers_dropped'] ?? null) === 1)
            ->once();
    }

    public function test_position_without_price_does_not_write_ladder(): void
    {
        $product = $this->createProduct('pt-noprice', 100.00);

        $this->handle([
            ['id' => 'pt-noprice', 'price_tiers' => [['min_quantity' => 2, 'price' => 90]]],
        ]);

        $this->assertDatabaseMissing('price_tiers', ['product_id' => $product->id]);
    }

    public function test_bulk_path_writes_prices_and_ladders_in_one_pass(): void
    {
        config()->set('rabbitmq-events.bulk_price_batch', true);

        $first = $this->createProduct('pt-bulk-1');
        $second = $this->createProduct('pt-bulk-2');
        $third = $this->createProduct('pt-bulk-3', 50.00);
        $this->seedLadder($third, [['min_quantity' => 4, 'price' => 40]]);

        // Bulk-путь не доходит до поштучного трансформера.
        $transformer = \Mockery::mock(ProductEventTransformer::class)->makePartial();
        $transformer->shouldNotReceive('processPriceUpdatedEvent');
        $this->app->instance(ProductEventTransformer::class, $transformer);

        $this->handle([
            ['id' => 'pt-bulk-1', 'price' => 279.00, 'price_tiers' => [['min_quantity' => 2, 'price' => 249]]],
            ['id' => 'pt-bulk-2', 'price' => 99.00],
            ['id' => 'pt-bulk-3', 'price' => 55.00, 'price_tiers' => []],
        ]);

        $this->assertEqualsWithDelta(279.0, $this->priceValue($first), 0.001);
        $this->assertEqualsWithDelta(99.0, $this->priceValue($second), 0.001);
        $this->assertEqualsWithDelta(55.0, $this->priceValue($third), 0.001);

        $this->assertSame(
            [['min_quantity' => 2.0, 'price' => 249.0]],
            $this->tiers->ladderFor($first->id, $this->shop->id)
        );
        $this->assertSame([], $this->tiers->ladderFor($second->id, $this->shop->id));
        $this->assertSame([], $this->tiers->ladderFor($third->id, $this->shop->id));
    }

    public function test_batch_owns_ladder_and_strips_key_from_per_item_payload(): void
    {
        $product = $this->createProduct('pt-strip', 100.00);

        $transformer = \Mockery::spy(ProductEventTransformer::class);
        $this->app->instance(ProductEventTransformer::class, $transformer);

        $this->handle([
            ['id' => 'pt-strip', 'price' => 150.00, 'price_tiers' => [['min_quantity' => 2, 'price' => 140]]],
        ]);

        $transformer->shouldHaveReceived('processPriceUpdatedEvent')
            ->withArgs(fn ($passedProduct, array $payload) => $passedProduct->id === $product->id
                && !array_key_exists('price_tiers', $payload)
                && (float) $payload['price'] === 150.0)
            ->once();

        // Лесенку записал сам батч, хотя трансформер заглушён.
        $this->assertSame(
            [['min_quantity' => 2.0, 'price' => 140.0]],
            $this->tiers->ladderFor($product->id, $this->shop->id)
        );
    }

    public function test_flag_disabled_ignores_key_on_both_paths(): void
    {
        $this->setPriceTiersModule(false);

        $perItem = $this->createProduct('pt-off-item', 100.00);
        $this->handle([
            ['id' => 'pt-off-item', 'price' => 150.00, 'price_tiers' => [['min_quantity' => 2, 'price' => 140]]],
        ]);

        config()->set('rabbitmq-events.bulk_price_batch', true);
        $bulk = $this->createProduct('pt-off-bulk');
        $this->handle([
            ['id' => 'pt-off-bulk', 'price' => 99.00, 'price_tiers' => [['min_quantity' => 2, 'price' => 90]]],
        ]);

        $this->assertEqualsWithDelta(150.0, $this->priceValue($perItem), 0.001);
        $this->assertEqualsWithDelta(99.0, $this->priceValue($bulk), 0.001);
        $this->assertDatabaseMissing('price_tiers', ['product_id' => $perItem->id]);
        $this->assertDatabaseMissing('price_tiers', ['product_id' => $bulk->id]);
    }

    public function test_flag_disabled_same_price_is_still_unchanged_despite_key(): void
    {
        $this->setPriceTiersModule(false);
        $product = $this->createProduct('pt-off-same', 279.00);

        $this->mock(CategoryQuantumService::class)->shouldNotReceive('applyToProduct');

        $this->handle([
            ['id' => 'pt-off-same', 'price' => 279.00, 'price_tiers' => [['min_quantity' => 2, 'price' => 249]]],
        ]);

        $this->assertDatabaseMissing('price_tiers', ['product_id' => $product->id]);
    }
}
