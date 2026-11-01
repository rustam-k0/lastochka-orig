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
 * Само-исцеление сырой единицы 1С (base_unit_type_id) в обработчике price_updated.
 *
 * До фикса залипшая единица (кг-товар с base_unit_type_id=грамм) не исправлялась:
 * полный product.updated из 1С приходит крайне редко, а price_updated единицу не
 * нёс. Нормализация цены к наименьшей единице делила сырую цену 1С на baseSize=1
 * вместо 1000 → price_per_kg/value раздувались в 1000 раз (LASTOCHKI-JAMI-11200).
 *
 * Теперь price_updated несёт measurement_unit, и base_unit_type_id корректируется
 * при каждом обновлении цены.
 */
class PriceUpdatedSelfHealBaseUnitTest extends TestCase
{
    use DatabaseTransactions;

    private ProductEventTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        config()->set('rabbitmq-events.enabled', false);
        config()->set('rabbitmq-events.shop_code', 'aladdin');
        // resolveShopId('aladdin') == config shop_code → shop_id=1.
        Shop::find(1) ?? Shop::forceCreate(['id' => 1, 'title' => 'Тест', 'code' => 'aladdin']);
        $this->transformer = app(ProductEventTransformer::class);
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    /**
     * Кг-товар с квантом 200 г, но base_unit залип на ГРАММ (состояние бага ×1000):
     * сырые 113 ₽/кг трактовались как 113 ₽/г → value=22600, price_per_kg=113000.
     */
    private function brokenWeightProduct(): Product
    {
        $product = Product::create([
            'title' => 'Бананы кг',
            'articul' => '1C-5129',
            'unit_type_id' => Type::UNIT_FOOD,
            'status_id' => 1,
            'info_weight_type_id' => Type::INFO_WEIGHT_GRAM,
            'base_unit_type_id' => Type::INFO_WEIGHT_GRAM,
            'shop_id' => 1,
            'manual_quantum_step' => 200,
            'manual_quantum_unit_type_id' => Type::INFO_WEIGHT_GRAM,
        ]);

        Price::create([
            'product_id' => $product->id,
            'shop_id' => 1,
            'type_id' => Type::PRICE_DEFAULT,
            'value' => 22600,
            'price_per_small_unit' => 113,
            'price_per_kg' => 113000,
            'step' => 200,
        ]);

        return $product->fresh();
    }

    private function defaultPrice(Product $p): Price
    {
        return Price::where('product_id', $p->id)
            ->where('type_id', Type::PRICE_DEFAULT)->first();
    }

    public function test_self_heals_base_unit_and_fixes_inflated_price(): void
    {
        $product = $this->brokenWeightProduct();

        $this->transformer->processPriceUpdatedEvent($product, [
            'price' => 113.00,
            'measurement_unit' => 'kilogram',
            'unit_type' => 'kg',
            'shop_code' => 'aladdin',
        ]);

        $product->refresh();
        $this->assertSame(Type::INFO_WEIGHT_KILOGRAM, (int) $product->base_unit_type_id);

        $price = $this->defaultPrice($product);
        // 113 ₽/кг → 0.113 ₽/г → квант 200 г = 22.6 ₽; price_per_kg назад к 113.
        $this->assertEqualsWithDelta(113.0, (float) $price->price_per_kg, 0.001);
        $this->assertEqualsWithDelta(22.6, (float) $price->value, 0.001);
        $this->assertEqualsWithDelta(0.113, (float) $price->price_per_small_unit, 0.0001);
    }

    public function test_keeps_base_unit_when_measurement_unit_absent(): void
    {
        $product = $this->brokenWeightProduct();

        // Событие без measurement_unit (старый формат) не трогает единицу.
        $this->transformer->processPriceUpdatedEvent($product, [
            'price' => 113.00,
            'shop_code' => 'aladdin',
        ]);

        $product->refresh();
        $this->assertSame(Type::INFO_WEIGHT_GRAM, (int) $product->base_unit_type_id);
    }
}
