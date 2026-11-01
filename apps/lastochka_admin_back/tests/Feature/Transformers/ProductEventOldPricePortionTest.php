<?php

namespace Tests\Feature\Transformers;

use App\Models\Price;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Type;
use App\Transformers\ProductEventTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Старая цена акции в событии товара — в той же единице, что и текущая (за порцию).
 *
 * price_without_discount хранится как пришла из 1С — за базовую единицу (кг),
 * а value пересчитан в цену порции. Событие слало их как есть: товар с
 * фасовкой 300 г при акции 650 → 594 ₽/кг уходил на витрину «650 → 178.20»,
 * ложная скидка −73%.
 */
class ProductEventOldPricePortionTest extends TestCase
{
    use DatabaseTransactions;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        config()->set('rabbitmq-events.shop_code', 'aladdin');
        $this->shop = Shop::forceCreate(['title' => 'Old price shop ' . uniqid(), 'code' => 'old-price-' . uniqid()]);
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    private function eventPrices(?int $infoWeight, int $infoType, int $baseUnit, float $value, ?float $withoutDiscount): array
    {
        $product = Product::create([
            'title' => 'Акционный товар ' . uniqid(),
            'articul' => 'OLD-' . uniqid(),
            'unit_type_id' => Type::UNIT_FOOD,
            'status_id' => 1,
            'info_weight' => $infoWeight,
            'info_weight_type_id' => $infoType,
            'base_unit_type_id' => $baseUnit,
            'shop_id' => $this->shop->id,
        ]);
        Price::create([
            'product_id' => $product->id,
            'shop_id' => $this->shop->id,
            'type_id' => Type::PRICE_DEFAULT,
            'value' => $value,
            'price_without_discount' => $withoutDiscount,
            'step' => $infoWeight ?? 1,
        ]);

        $event = app(ProductEventTransformer::class)->toEvent($product->fresh(), $this->shop);

        return [$event['price'], $event['discount_price']];
    }

    public function test_portioned_product_old_price_is_per_portion(): void
    {
        [$price, $discount] = $this->eventPrices(300, Type::INFO_WEIGHT_GRAM, Type::INFO_WEIGHT_KILOGRAM, 178.2, 650);

        $this->assertSame(195.0, $price);
        $this->assertSame(178.2, $discount);
    }

    public function test_kilogram_product_old_price_stays_per_kg(): void
    {
        [$price, $discount] = $this->eventPrices(null, Type::INFO_WEIGHT_KILOGRAM, Type::INFO_WEIGHT_KILOGRAM, 35, 40);

        $this->assertSame(40.0, $price);
        $this->assertSame(35.0, $discount);
    }

    public function test_piece_product_old_price_unchanged(): void
    {
        [$price, $discount] = $this->eventPrices(null, Type::INFO_WEIGHT_PIECE, Type::INFO_WEIGHT_PIECE, 259, 329);

        $this->assertSame(329.0, $price);
        $this->assertSame(259.0, $discount);
    }

    public function test_without_discount_price_is_value(): void
    {
        [$price, $discount] = $this->eventPrices(300, Type::INFO_WEIGHT_GRAM, Type::INFO_WEIGHT_KILOGRAM, 178.2, null);

        $this->assertSame(178.2, $price);
        $this->assertNull($discount);
    }
}
