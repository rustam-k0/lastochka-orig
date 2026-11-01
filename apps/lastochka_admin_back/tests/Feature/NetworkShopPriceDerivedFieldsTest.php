<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Price;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Type;
use App\NetworkCatalog\Support\NetworkCatalogMode;
use App\Services\ProductShopPrice\ProductShopPriceService;
use App\Services\Quantum\CategoryQuantumService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Ручная цена магазина (сетевой каталог) — цена ПОРЦИИ. Вместе с ней
 * пересчитываются цена за грамм, за кг и шаг строки: раньше писался только
 * value, и следующий пересчёт фасовки по старой цене за грамм откатывал
 * введённую оператором цену.
 */
class NetworkShopPriceDerivedFieldsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        Cache::forever(NetworkCatalogMode::CACHE_KEY, true);
    }

    protected function tearDown(): void
    {
        Cache::forget(NetworkCatalogMode::CACHE_KEY);
        Model::reguard();
        parent::tearDown();
    }

    public function test_manual_portion_price_survives_quantum_recalc(): void
    {
        $shop = Shop::forceCreate(['title' => 'Точка ' . uniqid(), 'code' => 'nsp-' . uniqid()]);
        $category = Category::create([
            'title' => 'Cat ' . uniqid(), 'quantum_step' => 300,
            'quantum_unit_type_id' => Type::INFO_WEIGHT_GRAM, 'is_active' => true,
        ]);
        $product = Product::create([
            'title' => 'Филе', 'articul' => 'NSP-' . uniqid(), 'status_id' => 1,
            'unit_type_id' => Type::UNIT_FOOD,
            'base_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM,
            'info_weight_type_id' => Type::INFO_WEIGHT_GRAM,
            'info_weight' => 300,
            'shop_id' => $shop->id,
        ]);
        $product->categories()->sync([$category->id]);
        Price::create([
            'product_id' => $product->id, 'shop_id' => $shop->id, 'type_id' => Type::PRICE_DEFAULT,
            'value' => 178.2, 'price_per_small_unit' => 0.594, 'price_per_kg' => 594, 'step' => 300,
        ]);

        app(ProductShopPriceService::class)->save($product, $shop, 200.0, 5.0);

        $row = Price::where('product_id', $product->id)->where('shop_id', $shop->id)->firstOrFail();
        $this->assertSame(200.0, (float) $row->value);
        $this->assertSame(300, (int) $row->step);
        $this->assertEqualsWithDelta(0.666667, (float) $row->price_per_small_unit, 0.000001);
        $this->assertSame(666.67, (float) $row->price_per_kg);

        app(CategoryQuantumService::class)->recalcCategory($category);

        $this->assertSame(200.0, (float) $row->fresh()->value, 'пересчёт фасовки не откатил ручную цену');
    }
}
