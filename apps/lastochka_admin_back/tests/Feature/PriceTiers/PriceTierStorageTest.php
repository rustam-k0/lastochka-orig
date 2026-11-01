<?php

declare(strict_types=1);

namespace Tests\Feature\PriceTiers;

use App\Models\PriceTier;
use App\Models\Product;
use App\Models\Shop;
use App\Services\Pricing\PriceTierLadder;
use App\Services\ProductShopPrice\ProductShopPriceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Хранение лесенки «цены от количества» (MID-308): таблица price_tiers,
 * модель, связь Product::priceTiers() и методы синхронизации сервиса.
 */
class PriceTierStorageTest extends TestCase
{
    use DatabaseTransactions;

    private ProductShopPriceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProductShopPriceService::class);
    }

    private function makeShop(): Shop
    {
        return Shop::create(['title' => 'Tier shop', 'code' => 'tier-shop-' . uniqid()]);
    }

    private function makeProduct(Shop $shop): Product
    {
        return Product::withoutEvents(fn () => Product::create([
            'title' => 'Tier product ' . uniqid(),
            'shop_id' => $shop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
        ]));
    }

    /** @return array<int, array{min_quantity: float, price: float}> */
    private function ladder(array $raw): array
    {
        return PriceTierLadder::normalize($raw);
    }

    public function test_table_has_contract_columns(): void
    {
        $this->assertTrue(Schema::hasTable('price_tiers'));
        $this->assertTrue(Schema::hasColumns('price_tiers', [
            'id', 'product_id', 'shop_id', 'min_quantity', 'value', 'created_at', 'updated_at',
        ]));
    }

    public function test_sync_writes_ladder_and_reports_change(): void
    {
        $shop = $this->makeShop();
        $product = $this->makeProduct($shop);

        $changed = $this->service->syncPriceTiers($product->id, $shop->id, $this->ladder([
            ['min_quantity' => 5, 'price' => 199],
            ['min_quantity' => 2, 'price' => 249],
        ]));

        $this->assertTrue($changed);
        $this->assertSame([
            ['min_quantity' => 2.0, 'price' => 249.0],
            ['min_quantity' => 5.0, 'price' => 199.0],
        ], $this->service->ladderFor($product->id, $shop->id));
    }

    public function test_same_ladder_is_not_rewritten(): void
    {
        $shop = $this->makeShop();
        $product = $this->makeProduct($shop);
        $ladder = $this->ladder([['min_quantity' => 2, 'price' => 249]]);

        $this->service->syncPriceTiers($product->id, $shop->id, $ladder);
        $idsBefore = DB::table('price_tiers')->where('product_id', $product->id)->pluck('id')->all();

        $this->assertFalse($this->service->syncPriceTiers($product->id, $shop->id, $ladder));
        $this->assertSame($idsBefore, DB::table('price_tiers')->where('product_id', $product->id)->pluck('id')->all());
    }

    public function test_empty_ladder_clears_only_that_shop(): void
    {
        $shopA = $this->makeShop();
        $shopB = $this->makeShop();
        $product = $this->makeProduct($shopA);
        $ladder = $this->ladder([['min_quantity' => 2, 'price' => 249]]);

        $this->service->syncPriceTiers($product->id, $shopA->id, $ladder);
        $this->service->syncPriceTiers($product->id, $shopB->id, $ladder);

        $this->assertTrue($this->service->syncPriceTiers($product->id, $shopA->id, []));

        $this->assertSame([], $this->service->ladderFor($product->id, $shopA->id));
        $this->assertSame($ladder, $this->service->ladderFor($product->id, $shopB->id));
    }

    public function test_fractional_threshold_survives_round_trip(): void
    {
        $shop = $this->makeShop();
        $product = $this->makeProduct($shop);

        $this->service->syncPriceTiers($product->id, $shop->id, $this->ladder([
            ['min_quantity' => 0.5, 'price' => 520.5],
        ]));

        $this->assertSame(
            [['min_quantity' => 0.5, 'price' => 520.5]],
            $this->service->ladderFor($product->id, $shop->id)
        );
    }

    public function test_bulk_replace_and_preload_by_shop(): void
    {
        $shop = $this->makeShop();
        $first = $this->makeProduct($shop);
        $second = $this->makeProduct($shop);
        $third = $this->makeProduct($shop);

        $this->service->syncPriceTiers($third->id, $shop->id, $this->ladder([['min_quantity' => 3, 'price' => 90]]));

        $this->service->replacePriceTiers($shop->id, [
            $first->id => $this->ladder([['min_quantity' => 2, 'price' => 249], ['min_quantity' => 5, 'price' => 199]]),
            $second->id => $this->ladder([['min_quantity' => 10, 'price' => 50]]),
            $third->id => [],
        ]);

        $ladders = $this->service->laddersForShop($shop->id, [$first->id, $second->id, $third->id]);

        $this->assertCount(2, $ladders[$first->id]);
        $this->assertSame([['min_quantity' => 10.0, 'price' => 50.0]], $ladders[$second->id]);
        $this->assertArrayNotHasKey($third->id, $ladders, 'пустая лесенка удаляет ступени товара');
    }

    public function test_one_ladder_for_many_shops(): void
    {
        $shopA = $this->makeShop();
        $shopB = $this->makeShop();
        $product = $this->makeProduct($shopA);
        $ladder = $this->ladder([['min_quantity' => 2, 'price' => 249]]);

        $this->service->syncPriceTiers($product->id, $shopA->id, $this->ladder([['min_quantity' => 7, 'price' => 1]]));
        $this->service->replacePriceTiersForShops($product->id, [$shopA->id, $shopB->id], $ladder);

        $this->assertSame($ladder, $this->service->ladderFor($product->id, $shopA->id));
        $this->assertSame($ladder, $this->service->ladderFor($product->id, $shopB->id));
    }

    public function test_product_relation_returns_all_shops_with_float_casts(): void
    {
        $shopA = $this->makeShop();
        $shopB = $this->makeShop();
        $product = $this->makeProduct($shopA);

        $this->service->syncPriceTiers($product->id, $shopA->id, $this->ladder([['min_quantity' => 2, 'price' => 249]]));
        $this->service->syncPriceTiers($product->id, $shopB->id, $this->ladder([['min_quantity' => 3, 'price' => 239.5]]));

        $tiers = $product->priceTiers()->get();

        $this->assertCount(2, $tiers);
        $this->assertContainsOnlyInstancesOf(PriceTier::class, $tiers);

        $tierB = $tiers->firstWhere('shop_id', $shopB->id);
        $this->assertSame(3.0, $tierB->min_quantity);
        $this->assertSame(239.5, $tierB->value);
        $this->assertSame($shopB->id, $tierB->shop_id);
    }
}
