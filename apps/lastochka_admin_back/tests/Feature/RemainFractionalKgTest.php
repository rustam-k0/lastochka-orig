<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Remain;
use App\Models\Shop;
use App\Models\Type;
use App\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Regression: остаток весового товара из 1С приходит в килограммах с дробной
 * частью (0.828, 2.168). remains.quantity была bigint, а processQuantity /
 * getProductQuantity приводили значение к int — товар с остатком меньше
 * килограмма хранился как 0, эхо product.updated от admin_back уносило этот
 * ноль на витрину и перетирало там корректные 828 г. Остаток должен
 * храниться и публиковаться с точностью до грамма (3 знака).
 */
class RemainFractionalKgTest extends TestCase
{
    use DatabaseTransactions;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'title' => 'Fractional Kg Shop ' . uniqid(),
            'code' => 'frac_kg_' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);
    }

    private function makeWeightProduct(): Product
    {
        return Product::create([
            'title' => 'Конфеты Вес ' . uniqid(),
            'shop_id' => $this->shop->id,
            'info_weight' => 200,
            'info_weight_type_id' => Type::INFO_WEIGHT_GRAM,
        ]);
    }

    /** @test */
    public function incoming_1c_event_keeps_fractional_kg_in_remain(): void
    {
        $product = $this->makeWeightProduct();

        app(ProductEventTransformer::class)->afterUpdate($product, [
            'id' => 'ext-' . uniqid(),
            'shop_code' => $this->shop->code,
            'quantity' => 0.828,
        ]);

        $remain = Remain::where('product_id', $product->id)->where('shop_id', $this->shop->id)->first();

        $this->assertNotNull($remain);
        $this->assertEqualsWithDelta(0.828, (float) $remain->quantity, 0.0005, 'Остаток 0.828 кг не должен усекаться до 0');
    }

    /** @test */
    public function outgoing_event_publishes_fractional_kg(): void
    {
        $product = $this->makeWeightProduct();
        Remain::upsertQuantity($product->id, $this->shop->id, 2.168);

        $event = app(ProductEventTransformer::class)->toEvent($product->fresh(), $this->shop);

        $this->assertEqualsWithDelta(2.168, (float) $event['quantity'], 0.0005, 'Эхо admin_back должно нести 2.168, а не 2');
    }

    /** @test */
    public function upsert_quantity_stores_fraction(): void
    {
        $product = $this->makeWeightProduct();

        $remain = Remain::upsertQuantity($product->id, $this->shop->id, 0.5);

        $this->assertEqualsWithDelta(0.5, (float) $remain->fresh()->quantity, 0.0005);
    }
}
