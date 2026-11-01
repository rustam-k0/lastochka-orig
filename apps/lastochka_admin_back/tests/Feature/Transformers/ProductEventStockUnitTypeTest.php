<?php

namespace Tests\Feature\Transformers;

use App\Models\Product;
use App\Models\Shop;
use App\Models\Type;
use App\Transformers\ProductEventTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * unit_type события товара — единица остатка (quantity) по базовой единице 1С.
 * Витрина переводит ×1000 только kg/l. Раньше для любого весового уходило 'kg'
 * (unit_type_id = UNIT_FOOD): остаток товара с базой «граммы» раздувался ×1000,
 * литры совпадали случайно.
 */
class ProductEventStockUnitTypeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    private function unitType(int $unitTypeId, ?int $baseUnit, ?int $infoType, ?int $infoWeight = null): string
    {
        $shop = Shop::forceCreate(['title' => 'Unit type shop ' . uniqid(), 'code' => 'ut-' . uniqid()]);
        $product = Product::create([
            'title' => 'P ' . uniqid(),
            'articul' => 'UT-' . uniqid(),
            'unit_type_id' => $unitTypeId,
            'status_id' => 1,
            'base_unit_type_id' => $baseUnit,
            'info_weight_type_id' => $infoType,
            'info_weight' => $infoWeight,
            'shop_id' => $shop->id,
        ]);

        return app(ProductEventTransformer::class)->toEvent($product->fresh(), $shop)['unit_type'];
    }

    public function test_kilogram_base_is_kg(): void
    {
        $this->assertSame('kg', $this->unitType(Type::UNIT_FOOD, Type::INFO_WEIGHT_KILOGRAM, Type::INFO_WEIGHT_GRAM, 300));
    }

    public function test_piece_is_piece(): void
    {
        $this->assertSame('piece', $this->unitType(Type::UNIT_PIECE, Type::INFO_WEIGHT_PIECE, Type::INFO_WEIGHT_PIECE));
    }

    public function test_gram_base_is_gram(): void
    {
        $this->assertSame('g', $this->unitType(Type::UNIT_FOOD, Type::INFO_WEIGHT_GRAM, Type::INFO_WEIGHT_GRAM));
    }

    public function test_liter_base_is_liter(): void
    {
        $this->assertSame('l', $this->unitType(Type::UNIT_FOOD, Type::INFO_WEIGHT_LITER, Type::INFO_WEIGHT_LITER));
    }

    public function test_without_units_keeps_legacy_mapping(): void
    {
        $this->assertSame('kg', $this->unitType(Type::UNIT_FOOD, null, null));
    }
}
