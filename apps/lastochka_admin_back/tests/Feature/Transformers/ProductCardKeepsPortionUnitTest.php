<?php

namespace Tests\Feature\Transformers;

use App\Models\Product;
use App\Models\Type;
use App\Transformers\ProductEventTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Событие карточки из 1С (measurement_unit = kilogram) не затирает единицу
 * порции товара с фасовкой: info_weight_type_id — единица порции (г), базовая
 * единица 1С хранится в base_unit_type_id. Шаг 1b ставил «кг» при порции 300 →
 * «порция 300 кг» в курьерке до пересчёта по цене, а у карточки без цены
 * (сетевой каталог) — насовсем.
 */
class ProductCardKeepsPortionUnitTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        config()->set('rabbitmq-events.enabled', false);
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    private function card(Product $product): void
    {
        app(ProductEventTransformer::class)->afterUpdate($product, [
            'id' => 'guid-' . $product->id,
            'sku' => (string) $product->articul,
            'title' => $product->title,
            'unit_type' => 'kg',
            'measurement_unit' => 'kilogram',
        ]);
    }

    public function test_portioned_product_keeps_gram_portion_unit(): void
    {
        $product = Product::create([
            'title' => 'Филе', 'articul' => 'PU-' . uniqid(), 'status_id' => 1,
            'unit_type_id' => Type::UNIT_FOOD,
            'base_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM,
            'info_weight_type_id' => Type::INFO_WEIGHT_GRAM,
            'info_weight' => 300,
        ]);

        $this->card($product);

        $product->refresh();
        $this->assertSame(Type::INFO_WEIGHT_GRAM, (int) $product->info_weight_type_id);
        $this->assertSame(300, (int) $product->info_weight);
    }

    public function test_product_without_quantum_follows_1c_unit(): void
    {
        $product = Product::create([
            'title' => 'Картофель', 'articul' => 'PU-' . uniqid(), 'status_id' => 1,
            'unit_type_id' => Type::UNIT_FOOD,
            'base_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM,
            'info_weight_type_id' => Type::INFO_WEIGHT_PIECE,
            'info_weight' => null,
        ]);

        $this->card($product);

        $this->assertSame(Type::INFO_WEIGHT_KILOGRAM, (int) $product->fresh()->info_weight_type_id);
    }
}
