<?php

namespace Tests\Feature;

use App\GraphQL\Queries\Product\RemainResolver;
use App\Models\Product;
use App\Models\Remain;
use App\Models\Shop;
use App\Models\Type;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Product.remain в API приложения сборщика — в единице порции
 * (info_weight_type_id): ею приложение подписывает остаток. В БД остаток
 * лежит в базовой единице 1С. Прод 14.10: «Филе грудки» 12.806 кг при порции
 * 300 г показывалось как «12 гр.».
 */
class RemainPortionUnitTest extends TestCase
{
    use DatabaseTransactions;

    private function productWithRemain(?int $baseUnit, int $infoWeightTypeId, ?int $infoWeight, float $remain): Product
    {
        $shop = Shop::forceCreate([
            'title' => 'Remain unit shop ' . uniqid(),
            'id_1c' => random_int(900000, 999999),
            'ignore_stock' => 0,
        ]);

        $product = Product::forceCreate([
            'title' => 'Remain unit product ' . uniqid(),
            'articul' => 'RU-' . uniqid(),
            'unit_type_id' => 1,
            'status_id' => 1,
            'shop_id' => $shop->id,
            'base_unit_type_id' => $baseUnit,
            'info_weight_type_id' => $infoWeightTypeId,
            'info_weight' => $infoWeight,
        ]);

        $row = new Remain();
        $row->forceFill([
            'product_id' => $product->id,
            'shop_id' => $shop->id,
            'quantity' => $remain,
        ])->save();

        $product->setRelation('remain', Remain::withoutGlobalScopes()->findOrFail($row->id));

        return $product;
    }

    public function test_portioned_product_remain_is_returned_in_grams(): void
    {
        $product = $this->productWithRemain(Type::INFO_WEIGHT_KILOGRAM, Type::INFO_WEIGHT_GRAM, 300, 12.806);

        $remain = (new RemainResolver())($product);

        $this->assertSame(12806.0, $remain->quantity);
        $this->assertSame(
            12.806,
            (float) Remain::withoutGlobalScopes()->where('product_id', $product->id)->value('quantity'),
            'В БД остаток остаётся в кг'
        );
    }

    public function test_kilogram_product_remain_stays_in_kg(): void
    {
        $product = $this->productWithRemain(Type::INFO_WEIGHT_KILOGRAM, Type::INFO_WEIGHT_KILOGRAM, null, 26.555);

        $this->assertSame(26.555, (new RemainResolver())($product)->quantity);
    }

    public function test_piece_product_remain_stays_in_pieces(): void
    {
        $product = $this->productWithRemain(Type::INFO_WEIGHT_PIECE, Type::INFO_WEIGHT_PIECE, null, 3);

        $this->assertSame(3.0, (new RemainResolver())($product)->quantity);
    }
}
