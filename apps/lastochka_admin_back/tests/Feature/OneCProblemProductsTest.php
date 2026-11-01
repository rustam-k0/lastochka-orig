<?php

namespace Tests\Feature;

use App\Models\OneCExchangeStatus;
use App\Models\OneCProblemProduct;
use App\Models\Shop;
use App\Transformers\OneCExchangeStatusEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * FOODLINK-11039. Снимок проблемных товаров 1С, наполняемый из RabbitMQ-диагностики.
 *
 * ВНИМАНИЕ: запускать ТОЛЬКО в изолированном окружении. Локально admin_back
 * phpunit.xml ходит в рабочую foodlink_custom — см. правило про тесты. Используем
 * DatabaseTransactions (rollBack), НЕ RefreshDatabase.
 */
class OneCProblemProductsTest extends TestCase
{
    use DatabaseTransactions;

    /** onec_exchange_statuses.shop_id — FK на shops, выдуманный id не вставится. */
    private function makeShopId(): int
    {
        return Shop::query()->create([
            'title' => 'fixture-1c-problems-' . uniqid(),
            'code' => '1c-problems-' . uniqid(),
            'address' => 'fixture',
            'phone' => '+70000000000',
        ])->id;
    }

    private function status(int $shopId, string $dataType): OneCExchangeStatus
    {
        return OneCExchangeStatus::create([
            'data_type' => $dataType . '_' . uniqid(),
            'success' => true,
            'records_count' => 0,
            'counters' => [],
            'warnings' => [],
            'last_exchange_at' => now(),
            'shop_id' => $shopId,
        ]);
    }

    public function test_after_create_persists_snapshot(): void
    {
        $shopId = $this->makeShopId();
        $transformer = new OneCExchangeStatusEventTransformer();
        $status = $this->status($shopId, 'catalog_import');

        $transformer->afterCreate($status, [
            'data_type' => 'catalog_import',
            'shop_id' => $shopId,
            'problem_product_codes' => ['missing_name', 'missing_sku', 'missing_barcode', 'missing_unit', 'missing_image'],
            'problem_products' => [
                'missing_sku' => [
                    ['external_id' => 'g-1', 'sku' => null, 'name' => 'Товар 1', 'barcode' => '460'],
                    ['external_id' => 'g-2', 'sku' => null, 'name' => 'Товар 2', 'barcode' => null],
                ],
                'missing_image' => [
                    ['external_id' => 'g-3', 'sku' => 'S3', 'name' => 'Товар 3', 'barcode' => null],
                ],
            ],
        ]);

        $this->assertEquals(2, OneCProblemProduct::where('shop_id', $shopId)->where('code', 'missing_sku')->count());
        $this->assertEquals(1, OneCProblemProduct::where('shop_id', $shopId)->where('code', 'missing_image')->count());
        $this->assertDatabaseHas('onec_problem_products', [
            'shop_id' => $shopId, 'code' => 'missing_sku', 'external_id' => 'g-1', 'name' => 'Товар 1',
        ]);
    }

    public function test_replace_snapshot_clears_fixed_codes(): void
    {
        $shopId = $this->makeShopId();
        $transformer = new OneCExchangeStatusEventTransformer();
        $status = $this->status($shopId, 'catalog_import');

        // Первый обмен: 2 без артикула
        $transformer->afterUpdate($status, [
            'data_type' => 'catalog_import',
            'shop_id' => $shopId,
            'problem_product_codes' => ['missing_name', 'missing_sku', 'missing_barcode', 'missing_unit', 'missing_image'],
            'problem_products' => [
                'missing_sku' => [
                    ['external_id' => 'g-1', 'sku' => null, 'name' => 'A', 'barcode' => null],
                    ['external_id' => 'g-2', 'sku' => null, 'name' => 'B', 'barcode' => null],
                ],
            ],
        ]);
        $this->assertEquals(2, OneCProblemProduct::where('shop_id', $shopId)->where('code', 'missing_sku')->count());

        // Второй обмен: артикул починили (код стал 0) — старые строки чистятся,
        // т.к. missing_sku в problem_product_codes, хоть и без products
        $transformer->afterUpdate($status, [
            'data_type' => 'catalog_import',
            'shop_id' => $shopId,
            'problem_product_codes' => ['missing_name', 'missing_sku', 'missing_barcode', 'missing_unit', 'missing_image'],
            'problem_products' => [
                'missing_barcode' => [
                    ['external_id' => 'g-9', 'sku' => 'S9', 'name' => 'C', 'barcode' => null],
                ],
            ],
        ]);

        $this->assertEquals(0, OneCProblemProduct::where('shop_id', $shopId)->where('code', 'missing_sku')->count());
        $this->assertEquals(1, OneCProblemProduct::where('shop_id', $shopId)->where('code', 'missing_barcode')->count());
    }

    public function test_offers_exchange_does_not_touch_catalog_codes(): void
    {
        $shopId = $this->makeShopId();
        $transformer = new OneCExchangeStatusEventTransformer();

        // Каталог положил missing_sku
        $catalog = $this->status($shopId, 'catalog_import');
        $transformer->afterUpdate($catalog, [
            'shop_id' => $shopId,
            'problem_product_codes' => ['missing_name', 'missing_sku', 'missing_barcode', 'missing_unit', 'missing_image'],
            'problem_products' => [
                'missing_sku' => [['external_id' => 'g-1', 'sku' => null, 'name' => 'A', 'barcode' => null]],
            ],
        ]);

        // Обмен цен/остатков НЕ должен затронуть каталожный missing_sku
        $offers = $this->status($shopId, 'offers_import');
        $transformer->afterUpdate($offers, [
            'shop_id' => $shopId,
            'problem_product_codes' => ['missing_price', 'missing_stock'],
            'problem_products' => [
                'missing_price' => [['external_id' => 'g-5', 'sku' => 'S5', 'name' => 'P', 'barcode' => null]],
            ],
        ]);

        $this->assertEquals(1, OneCProblemProduct::where('shop_id', $shopId)->where('code', 'missing_sku')->count());
        $this->assertEquals(1, OneCProblemProduct::where('shop_id', $shopId)->where('code', 'missing_price')->count());
    }

    public function test_rests_exchange_without_codes_is_noop(): void
    {
        $shopId = $this->makeShopId();
        $transformer = new OneCExchangeStatusEventTransformer();
        $status = $this->status($shopId, 'rests_import');

        $transformer->afterUpdate($status, [
            'shop_id' => $shopId,
            'problem_product_codes' => [],
            'problem_products' => [],
        ]);

        $this->assertEquals(0, OneCProblemProduct::where('shop_id', $shopId)->count());
    }
}
