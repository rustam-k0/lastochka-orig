<?php

namespace Tests\Feature\Transformers;

use App\Models\Category;
use App\Models\Price;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Type;
use App\Services\Quantum\UnitFamily;
use App\Transformers\ProductEventTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Регрессия: processPriceUpdatedEvent() корректно применял скидку через
 * processPrice() (value=discount, price_without_discount=price), но следом
 * безусловно вызывал CategoryQuantumService::applyToProduct() с сырой
 * БАЗОВОЙ ценой из события — та пересчитывала value заново от базовой цены
 * и затирала только что записанную акционную. Обнаружено вживую на dev
 * (LASTOCHKI promo-discount-price E2E, 2026-07-06): 1С-товар с value=329/
 * discount=259 после applyToProduct откатывался на value=329.
 *
 * Фикс — resolveEffectivePrice() отдаёт applyToProduct() ту же цену
 * (акционную, если есть), что уже легла в processPrice().
 */
class PriceUpdatedDiscountQuantumTest extends TestCase
{
    use DatabaseTransactions;

    private ProductEventTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        config()->set('rabbitmq-events.enabled', false);
        config()->set('rabbitmq-events.shop_code', 'aladdin');
        Shop::find(1) ?? Shop::forceCreate(['id' => 1, 'title' => 'Тест', 'code' => 'aladdin']);
        $this->transformer = app(ProductEventTransformer::class);
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    private function defaultPrice(Product $p): Price
    {
        return Price::where('product_id', $p->id)
            ->where('type_id', Type::PRICE_DEFAULT)->first();
    }

    public function test_discount_price_survives_quantum_recalc_for_piece_product(): void
    {
        $product = Product::create([
            'title' => 'Штучный товар со скидкой',
            'articul' => '1C-DISCOUNT-1',
            'unit_type_id' => Type::UNIT_FOOD,
            'status_id' => 1,
            'info_weight_type_id' => Type::INFO_WEIGHT_PIECE,
            'base_unit_type_id' => Type::INFO_WEIGHT_PIECE,
            'shop_id' => 1,
        ]);

        $this->transformer->processPriceUpdatedEvent($product, [
            'price' => 329.00,
            'discount_price' => 259.00,
            'unit_type' => 'piece',
            'measurement_unit' => 'piece',
            'shop_code' => 'aladdin',
        ]);

        $price = $this->defaultPrice($product);
        $this->assertEqualsWithDelta(259.0, (float) $price->value, 0.001);
        $this->assertEqualsWithDelta(329.0, (float) $price->price_without_discount, 0.001);
    }

    public function test_price_updated_without_discount_clears_price_without_discount(): void
    {
        $product = Product::create([
            'title' => 'Штучный товар без скидки',
            'articul' => '1C-NO-DISCOUNT-1',
            'unit_type_id' => Type::UNIT_FOOD,
            'status_id' => 1,
            'info_weight_type_id' => Type::INFO_WEIGHT_PIECE,
            'base_unit_type_id' => Type::INFO_WEIGHT_PIECE,
            'shop_id' => 1,
        ]);

        Price::create([
            'product_id' => $product->id,
            'shop_id' => 1,
            'type_id' => Type::PRICE_DEFAULT,
            'value' => 259.00,
            'price_without_discount' => 329.00,
            'step' => 1,
        ]);

        // 1С перестал слать акционную цену — акция закончилась.
        $this->transformer->processPriceUpdatedEvent($product, [
            'price' => 329.00,
            'unit_type' => 'piece',
            'measurement_unit' => 'piece',
            'shop_code' => 'aladdin',
        ]);

        $price = $this->defaultPrice($product);
        $this->assertEqualsWithDelta(329.0, (float) $price->value, 0.001);
        $this->assertNull($price->price_without_discount);
    }

    public function test_discount_price_survives_quantum_recalc_for_weighted_product(): void
    {
        $category = Category::create([
            'title' => 'Весовая категория ' . uniqid(),
            'quantum_step' => 200,
            'quantum_unit_type_id' => Type::INFO_WEIGHT_GRAM,
            'is_active' => true,
        ]);

        $product = Product::create([
            'title' => 'Весовой товар со скидкой',
            'articul' => '1C-DISCOUNT-KG',
            'unit_type_id' => Type::UNIT_FOOD,
            'status_id' => 1,
            'info_weight_type_id' => Type::INFO_WEIGHT_KILOGRAM,
            'base_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM,
            'shop_id' => 1,
        ]);
        $product->categories()->sync([$category->id]);

        // Базовая 400 ₽/кг, акционная 300 ₽/кг → квант 200 г: 80 ₽ вместо 60 ₽.
        $this->transformer->processPriceUpdatedEvent($product, [
            'price' => 400.00,
            'discount_price' => 300.00,
            'unit_type' => 'kg',
            'measurement_unit' => 'kilogram',
            'shop_code' => 'aladdin',
        ]);

        $price = $this->defaultPrice($product);
        $this->assertEqualsWithDelta(60.0, (float) $price->value, 0.001);
        $this->assertEqualsWithDelta(0.3, (float) $price->price_per_small_unit, 0.0001);
        $this->assertEqualsWithDelta(300.0, (float) $price->price_per_kg, 0.001);
        // price_without_discount хранит базовую цену как пришла в событии (за кг),
        // processPrice() не квант-пересчитывает это поле.
        $this->assertEqualsWithDelta(400.0, (float) $price->price_without_discount, 0.001);
    }
}
