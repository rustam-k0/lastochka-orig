<?php

declare(strict_types=1);

namespace Tests\Feature\Aggregator;

use App\GraphQL\Queries\QuarantineNoBarcode;
use App\Models\AggregatorQuarantine;
use App\Models\OneCProblemProduct;
use App\Models\Product;
use Database\Factories\ShopFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * FOODLINK-11458. Резолв `local_product_id` (id товара курьерки) для строк карантина,
 * чтобы фронт строил ссылку на карточку товара.
 *
 * Резолв 1С → курьерка по уникальному ключу products(shop_id, articul=sku).
 * Запускать ТОЛЬКО локально (DatabaseTransactions → rollBack, не дропает БД).
 */
class QuarantineLocalProductIdTest extends TestCase
{
    use DatabaseTransactions;

    /** OneCProblemProduct резолвит local_product_id по (shop_id, articul=sku). */
    public function test_onec_problem_product_resolves_local_product_id(): void
    {
        $shop = $this->makeShop();
        $product = $this->makeProduct($shop->id, 'SKU-11458-A');

        $problem = OneCProblemProduct::create([
            'shop_id' => $shop->id,
            'code' => 'missing_image',
            'external_id' => 'guid-1',
            'sku' => 'SKU-11458-A',
            'name' => 'Товар А',
            'barcode' => null,
        ]);

        $this->assertSame($product->id, $problem->local_product_id);
    }

    /** Нет товара с таким articul → local_product_id = null. */
    public function test_onec_problem_product_local_product_id_null_when_no_product(): void
    {
        $shop = $this->makeShop();

        $problem = OneCProblemProduct::create([
            'shop_id' => $shop->id,
            'code' => 'missing_image',
            'external_id' => 'guid-2',
            'sku' => 'SKU-11458-UNKNOWN',
            'name' => 'Товар без курьерки',
            'barcode' => null,
        ]);

        $this->assertNull($problem->local_product_id);
    }

    /** Пустой sku (missing_sku) → резолвить нечем → null. */
    public function test_onec_problem_product_local_product_id_null_when_sku_empty(): void
    {
        $shop = $this->makeShop();

        $problem = OneCProblemProduct::create([
            'shop_id' => $shop->id,
            'code' => 'missing_sku',
            'external_id' => 'guid-3',
            'sku' => null,
            'name' => 'Товар без артикула',
            'barcode' => null,
        ]);

        $this->assertNull($problem->local_product_id);
    }

    /** Резолв учитывает shop_id: тот же articul в другом магазине не матчится. */
    public function test_onec_problem_product_local_product_id_is_scoped_by_shop(): void
    {
        $shopA = $this->makeShop();
        $shopB = $this->makeShop();
        $this->makeProduct($shopA->id, 'SKU-11458-S');

        $problem = OneCProblemProduct::create([
            'shop_id' => $shopB->id,
            'code' => 'missing_image',
            'external_id' => 'guid-4',
            'sku' => 'SKU-11458-S',
            'name' => 'Чужой магазин',
            'barcode' => null,
        ]);

        $this->assertNull($problem->local_product_id);
    }

    /** union «Без штрихкода»: строка-quarantine отдаёт local_product_id из q.local_product_id. */
    public function test_union_exposes_local_product_id_for_quarantine_source(): void
    {
        $shop = $this->makeShop();
        $product = $this->makeProduct($shop->id, 'SKU-11458-Q');

        $q = AggregatorQuarantine::create([
            'shop_id' => $shop->id,
            'local_product_id' => $product->id,
            'incoming_title' => 'Товар в карантине no_ean',
            'reason' => AggregatorQuarantine::REASON_NO_EAN,
        ]);

        $row = $this->unionRow($shop->id, 'quarantine', $q->id);

        $this->assertNotNull($row, 'quarantine-строка должна попасть в union');
        $this->assertEquals($product->id, $row->local_product_id);
    }

    /** union «Без штрихкода»: строка-onec резолвит local_product_id через leftJoin products. */
    public function test_union_resolves_local_product_id_for_onec_source(): void
    {
        $shop = $this->makeShop();
        $product = $this->makeProduct($shop->id, 'SKU-11458-O');

        $problem = OneCProblemProduct::create([
            'shop_id' => $shop->id,
            'code' => 'missing_barcode',
            'external_id' => 'guid-5',
            'sku' => 'SKU-11458-O',
            'name' => 'Товар 1С без штрихкода',
            'barcode' => null,
        ]);

        $row = $this->unionRow($shop->id, 'onec', $problem->id);

        $this->assertNotNull($row, 'onec-строка должна попасть в union');
        $this->assertEquals($product->id, $row->local_product_id);
    }

    /** union «Без штрихкода»: onec без товара курьерки → local_product_id = null. */
    public function test_union_local_product_id_null_for_onec_without_product(): void
    {
        $shop = $this->makeShop();

        $problem = OneCProblemProduct::create([
            'shop_id' => $shop->id,
            'code' => 'missing_barcode',
            'external_id' => 'guid-6',
            'sku' => 'SKU-11458-NONE',
            'name' => 'Товар 1С без курьерки',
            'barcode' => null,
        ]);

        $row = $this->unionRow($shop->id, 'onec', $problem->id);

        $this->assertNotNull($row, 'onec-строка должна попасть в union');
        $this->assertNull($row->local_product_id);
    }

    /** Shop с валидными для локальной схемы значениями (commission/deposit малыми). */
    private function makeShop(): \App\Models\Shop
    {
        return ShopFactory::new()->create(['title' => 'Тест-магазин 11458', 'commission' => 0, 'deposit' => 0]);
    }

    /** Товар курьерки напрямую (ProductFactory нерабоча: её definition зовёт Shop::factory()). */
    private function makeProduct(int $shopId, string $articul): Product
    {
        return Product::create([
            'shop_id' => $shopId,
            'articul' => $articul,
            'title' => 'Товар ' . $articul,
        ]);
    }

    /** Выполняет union-builder и возвращает строку нужного источника по id. */
    private function unionRow(int $shopId, string $source, int $id): ?object
    {
        $rows = (new QuarantineNoBarcode())->builder(null, ['shop_id' => $shopId])->get();

        return $rows->first(fn ($r) => $r->source === $source && (int) $r->id === $id);
    }
}
