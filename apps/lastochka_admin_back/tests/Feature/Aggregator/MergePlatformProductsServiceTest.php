<?php

namespace Tests\Feature\Aggregator;

use App\Aggregator\Services\MergePlatformProductsService;
use App\Models\PlatformProduct;
use App\Models\PlatformProductBarcode;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Shop;
use App\Models\Status;
use App\Models\Type;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Тесты объединения дублей платформенных карточек (FOODLINK-10874, §8.3.1, §11.1).
 */
class MergePlatformProductsServiceTest extends TestCase
{
    use DatabaseTransactions;

    private MergePlatformProductsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MergePlatformProductsService();
    }

    private function makeShop(string $code): Shop
    {
        return Shop::create(['code' => $code, 'title' => $code]);
    }

    private function makeProduct(Shop $shop): Product
    {
        return Product::withoutEvents(fn () => Product::create([
            'title' => 'p' . uniqid(),
            'articul' => uniqid('a_'),
            'shop_id' => $shop->id,
            'status_id' => Status::CODE_ACTIVE,
            'unit_type_id' => Type::UNIT_PIECE,
        ]));
    }

    private function makeCard(string $title, array $eans, array $offerShops): PlatformProduct
    {
        $card = PlatformProduct::create(['canonical_title' => $title, 'is_active' => true]);
        foreach ($eans as $i => $ean) {
            PlatformProductBarcode::create([
                'platform_product_id' => $card->id,
                'ean' => $ean,
                'is_primary' => $i === 0,
            ]);
        }
        foreach ($offerShops as $shop) {
            ProductOffer::create([
                'platform_product_id' => $card->id,
                'shop_id' => $shop->id,
                'local_product_id' => $this->makeProduct($shop)->id,
                'price' => 1000,
                'qty' => 10,
                'min_qty' => 1,
            ]);
        }
        return $card->fresh();
    }

    public function test_merge_moves_offers_and_barcodes_and_archives_source(): void
    {
        $shopA = $this->makeShop('a');
        $shopB = $this->makeShop('b');

        $target = $this->makeCard('Молоко', ['1111111111111'], [$shopA]);
        $source = $this->makeCard('Молоко 3.2', ['2222222222222'], [$shopB]);

        $result = $this->service->merge($target->id, [$source->id]);

        // Офферы и штрихкоды на target.
        $this->assertSame(2, $result->offers()->count());
        $this->assertSame(2, $result->barcodes()->count());
        // Источник — в архив (soft-delete), не удалён физически.
        $this->assertSoftDeleted('platform_products', ['id' => $source->id]);
        // Ровно один primary.
        $this->assertSame(1, $result->barcodes()->where('is_primary', true)->count());
    }

    public function test_merge_conflict_same_shop_drops_source_offer(): void
    {
        $shopA = $this->makeShop('a');

        // Обе карточки имеют оффер в shopA → конфликт: оффер источника отбрасывается.
        $target = $this->makeCard('Молоко', ['1111111111111'], [$shopA]);
        $source = $this->makeCard('Молоко дубль', ['2222222222222'], [$shopA]);

        $result = $this->service->merge($target->id, [$source->id]);

        // Только один оффер (target'а) — оффер источника по тому же shop отброшен.
        $this->assertSame(1, $result->offers()->count());
        $this->assertSame($shopA->id, $result->offers()->first()->shop_id);
    }

    public function test_preview_reports_offers_stocks_and_conflicts(): void
    {
        $shopA = $this->makeShop('a');
        $shopB = $this->makeShop('b');

        $target = $this->makeCard('Молоко', ['1111111111111'], [$shopA]);
        // источник: оффер в shopA (конфликт) + оффер в shopB (перенос), остатки 10+10.
        $source = $this->makeCard('Дубль', ['2222222222222'], [$shopA, $shopB]);

        $preview = $this->service->preview($target->id, [$source->id]);

        $this->assertSame(2, $preview['offersCount']);
        $this->assertEquals(20.0, $preview['stocksSum']);
        $this->assertCount(1, $preview['conflicts']);
        $this->assertSame($shopA->id, $preview['conflicts'][0]['shopId']);
    }

    public function test_merge_ignores_target_in_source_ids(): void
    {
        $shopA = $this->makeShop('a');
        $target = $this->makeCard('Молоко', ['1111111111111'], [$shopA]);

        // targetId присутствует и в ids — не должен само-удалиться.
        $result = $this->service->merge($target->id, [$target->id]);

        $this->assertFalse($result->trashed());
        $this->assertSame(1, $result->offers()->count());
    }
}
