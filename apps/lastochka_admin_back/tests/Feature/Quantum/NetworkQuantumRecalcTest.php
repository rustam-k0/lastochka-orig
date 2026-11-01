<?php

namespace Tests\Feature\Quantum;

use App\Models\Category;
use App\Models\Price;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Type;
use App\NetworkCatalog\Support\NetworkCatalogMode;
use App\Services\Quantum\CategoryQuantumService;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Сетевой каталог: смена фасовки категории пересчитывает цену порции во ВСЕХ
 * магазинах товара и публикует офферы (price_updated) по каждому. Раньше
 * пересчитывалась только строка якорного магазина, а без офферных событий
 * витрина держала старую цену порции при новом размере.
 */
class NetworkQuantumRecalcTest extends TestCase
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

    public function test_recalc_category_updates_every_shop_price_and_publishes_offers(): void
    {
        $anchor = Shop::forceCreate(['title' => 'Якорь ' . uniqid(), 'code' => 'anchor-' . uniqid()]);
        $other = Shop::forceCreate(['title' => 'Точка ' . uniqid(), 'code' => 'point-' . uniqid()]);
        $category = Category::create([
            'title' => 'Cat ' . uniqid(), 'quantum_step' => 300,
            'quantum_unit_type_id' => Type::INFO_WEIGHT_GRAM, 'is_active' => true,
        ]);
        $product = Product::create([
            'title' => 'Филе', 'articul' => 'NQ-' . uniqid(), 'status_id' => 1,
            'unit_type_id' => Type::UNIT_FOOD,
            'base_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM,
            'info_weight_type_id' => Type::INFO_WEIGHT_KILOGRAM,
            'shop_id' => $anchor->id,
        ]);
        $product->categories()->sync([$category->id]);
        foreach ([[$anchor, 594], [$other, 650]] as [$shop, $perKg]) {
            Price::create([
                'product_id' => $product->id, 'shop_id' => $shop->id, 'type_id' => Type::PRICE_DEFAULT,
                'value' => $perKg, 'price_per_small_unit' => $perKg / 1000, 'step' => 1,
            ]);
        }

        app(CategoryQuantumService::class)->recalcCategory($category);

        $value = fn (Shop $shop) => (float) Price::where('product_id', $product->id)->where('shop_id', $shop->id)->value('value');
        $this->assertSame(178.2, $value($anchor));
        $this->assertSame(195.0, $value($other));

        foreach ([$anchor, $other] as $shop) {
            $this->assertSame(1, EventOutbox::where('entity_type', 'products')
                ->where('entity_id', $product->id)
                ->where('event_type', 'price_updated')
                ->where('payload->shop_code', $shop->code)
                ->count(), "оффер магазина {$shop->code}");
        }
    }
}
