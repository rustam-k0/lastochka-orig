<?php

declare(strict_types=1);

namespace Tests\Feature\PriceTiers;

use App\Models\Price;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Type;
use App\NetworkCatalog\Support\NetworkCatalogMode;
use App\Services\ModuleConfigService;
use App\Services\Pricing\PriceTierLadder;
use App\Services\ProductShopPrice\ProductShopPriceService;
use App\Transformers\ProductEventTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithPriceTiers;
use Tests\TestCase;

/**
 * Приём ключа price_tiers из product.price_updated и карточного события
 * (MID-308): ключа нет — не трогаем, [] / null — очистка, массив — полная
 * замена, битая лесенка отбрасывается, а цена применяется; при выключенном
 * флаге ключ игнорируется.
 */
class PriceTiersInboundEventTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithPriceTiers;

    private ProductEventTransformer $transformer;
    private ProductShopPriceService $tiers;
    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        config()->set('rabbitmq-events.enabled', false);
        Cache::forever(NetworkCatalogMode::CACHE_KEY, false);

        // Код уникален на тест: resolveShopId кэширует code → id статически.
        $this->shop = Shop::create(['title' => 'Магазин лесенок', 'code' => 'tiers-' . uniqid()]);
        $this->transformer = app(ProductEventTransformer::class);
        $this->tiers = app(ProductShopPriceService::class);

        $this->setPriceTiersModule(true);
    }

    protected function tearDown(): void
    {
        Cache::forget(NetworkCatalogMode::CACHE_KEY);
        Cache::forget(NetworkCatalogMode::ANCHOR_CACHE_KEY);
        ModuleConfigService::invalidateCache();
        Model::reguard();
        parent::tearDown();
    }

    private function makeProduct(): Product
    {
        return Product::create([
            'title' => 'Штучный товар ' . uniqid(),
            'articul' => 'TIER-' . uniqid(),
            'unit_type_id' => Type::UNIT_FOOD,
            'status_id' => 1,
            'info_weight_type_id' => Type::INFO_WEIGHT_PIECE,
            'base_unit_type_id' => Type::INFO_WEIGHT_PIECE,
            'shop_id' => $this->shop->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function priceEvent(array $overrides = []): array
    {
        return array_merge([
            'event_id' => (string) Str::uuid(),
            'price' => 279.00,
            'unit_type' => 'piece',
            'measurement_unit' => 'piece',
            'shop_code' => $this->shop->code,
        ], $overrides);
    }

    private function priceValue(Product $product): float
    {
        return (float) Price::where('product_id', $product->id)
            ->where('shop_id', $this->shop->id)
            ->where('type_id', Type::PRICE_DEFAULT)
            ->value('value');
    }

    private function seedLadder(Product $product): void
    {
        $this->tiers->syncPriceTiers($product->id, $this->shop->id, PriceTierLadder::normalize([
            ['min_quantity' => 3, 'price' => 100],
        ]));
    }

    public function test_price_updated_writes_ladder_of_event_shop(): void
    {
        $product = $this->makeProduct();

        $this->transformer->processPriceUpdatedEvent($product, $this->priceEvent([
            'price_tiers' => [
                ['min_quantity' => 5, 'price' => 199],
                ['min_quantity' => 2, 'price' => 249],
            ],
        ]));

        $this->assertSame([
            ['min_quantity' => 2.0, 'price' => 249.0],
            ['min_quantity' => 5.0, 'price' => 199.0],
        ], $this->tiers->ladderFor($product->id, $this->shop->id));
        $this->assertEqualsWithDelta(279.0, $this->priceValue($product), 0.001);
    }

    public function test_event_without_key_keeps_ladder(): void
    {
        $product = $this->makeProduct();
        $this->seedLadder($product);

        $this->transformer->processPriceUpdatedEvent($product, $this->priceEvent(['price' => 300.00]));

        $this->assertSame(
            [['min_quantity' => 3.0, 'price' => 100.0]],
            $this->tiers->ladderFor($product->id, $this->shop->id)
        );
        $this->assertEqualsWithDelta(300.0, $this->priceValue($product), 0.001);
    }

    public function test_empty_array_and_null_clear_ladder(): void
    {
        $product = $this->makeProduct();

        $this->seedLadder($product);
        $this->transformer->processPriceUpdatedEvent($product, $this->priceEvent(['price_tiers' => []]));
        $this->assertSame([], $this->tiers->ladderFor($product->id, $this->shop->id));

        $this->seedLadder($product);
        $this->transformer->processPriceUpdatedEvent($product, $this->priceEvent(['price_tiers' => null]));
        $this->assertSame([], $this->tiers->ladderFor($product->id, $this->shop->id));
    }

    public function test_broken_ladder_is_dropped_but_price_applies(): void
    {
        $product = $this->makeProduct();
        $this->seedLadder($product);
        Log::spy();

        $this->transformer->processPriceUpdatedEvent($product, $this->priceEvent([
            'price' => 311.00,
            'price_tiers' => [
                ['min_quantity' => 2, 'price' => 249],
                ['min_quantity' => 2, 'price' => 199],
            ],
        ]));

        $this->assertSame(
            [['min_quantity' => 3.0, 'price' => 100.0]],
            $this->tiers->ladderFor($product->id, $this->shop->id),
            'битая лесенка не должна затирать сохранённую'
        );
        $this->assertEqualsWithDelta(311.0, $this->priceValue($product), 0.001);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => $message === 'price_tiers dropped: invalid ladder, price applied without tiers')
            ->once();
    }

    public function test_non_monotonic_ladder_from_1c_is_accepted(): void
    {
        $product = $this->makeProduct();

        $this->transformer->processPriceUpdatedEvent($product, $this->priceEvent([
            'price_tiers' => [
                ['min_quantity' => 1, 'price' => 100],
                ['min_quantity' => 3, 'price' => 120],
            ],
        ]));

        $this->assertCount(2, $this->tiers->ladderFor($product->id, $this->shop->id));
    }

    public function test_event_without_price_does_not_touch_ladder(): void
    {
        $product = $this->makeProduct();
        $this->seedLadder($product);

        $event = $this->priceEvent(['price_tiers' => []]);
        unset($event['price']);
        $this->transformer->processPriceUpdatedEvent($product, $event);

        $this->assertCount(1, $this->tiers->ladderFor($product->id, $this->shop->id));
    }

    public function test_flag_disabled_ignores_key_and_applies_price(): void
    {
        $this->setPriceTiersModule(false);
        $product = $this->makeProduct();

        $this->transformer->processPriceUpdatedEvent($product, $this->priceEvent([
            'price' => 279.00,
            'price_tiers' => [['min_quantity' => 2, 'price' => 249]],
        ]));

        $this->assertDatabaseMissing('price_tiers', ['product_id' => $product->id]);
        $this->assertEqualsWithDelta(279.0, $this->priceValue($product), 0.001);
    }

    public function test_flag_disabled_does_not_clear_existing_ladder(): void
    {
        $product = $this->makeProduct();
        $this->seedLadder($product);
        $this->setPriceTiersModule(false);

        $this->transformer->processPriceUpdatedEvent($product, $this->priceEvent(['price_tiers' => []]));

        $this->assertDatabaseHas('price_tiers', ['product_id' => $product->id, 'shop_id' => $this->shop->id]);
    }

    public function test_card_event_writes_ladder_under_price_condition(): void
    {
        $product = $this->makeProduct();

        $this->transformer->afterUpdate($product, [
            'event_id' => (string) Str::uuid(),
            'id' => 'guid-' . uniqid(),
            'source' => '1c',
            'shop_code' => $this->shop->code,
            'price' => 279.00,
            'unit_type' => 'piece',
            'price_tiers' => [['min_quantity' => 2, 'price' => 249]],
        ]);

        $this->assertSame(
            [['min_quantity' => 2.0, 'price' => 249.0]],
            $this->tiers->ladderFor($product->id, $this->shop->id)
        );
    }

    public function test_card_event_without_price_does_not_touch_ladder(): void
    {
        $product = $this->makeProduct();
        $this->seedLadder($product);

        $this->transformer->afterUpdate($product, [
            'event_id' => (string) Str::uuid(),
            'id' => 'guid-' . uniqid(),
            'source' => '1c',
            'shop_code' => $this->shop->code,
            'unit_type' => 'piece',
            'price_tiers' => [],
        ]);

        $this->assertCount(1, $this->tiers->ladderFor($product->id, $this->shop->id));
    }

    public function test_network_card_without_shop_code_writes_no_ladder(): void
    {
        Cache::forever(NetworkCatalogMode::CACHE_KEY, true);
        Cache::forever(NetworkCatalogMode::ANCHOR_CACHE_KEY, $this->shop->id);
        $product = $this->makeProduct();

        $this->transformer->afterUpdate($product, [
            'event_id' => (string) Str::uuid(),
            'id' => 'guid-' . uniqid(),
            'source' => '1c',
            'price' => 279.00,
            'unit_type' => 'piece',
            'price_tiers' => [['min_quantity' => 2, 'price' => 249]],
        ]);

        $this->assertDatabaseMissing('price_tiers', ['product_id' => $product->id]);
    }

    public function test_network_price_updated_without_shop_code_is_dropped_with_ladder(): void
    {
        Cache::forever(NetworkCatalogMode::CACHE_KEY, true);
        $product = $this->makeProduct();

        $event = $this->priceEvent(['price_tiers' => [['min_quantity' => 2, 'price' => 249]]]);
        unset($event['shop_code']);
        $this->transformer->processPriceUpdatedEvent($product, $event);

        $this->assertDatabaseMissing('price_tiers', ['product_id' => $product->id]);
    }
}
