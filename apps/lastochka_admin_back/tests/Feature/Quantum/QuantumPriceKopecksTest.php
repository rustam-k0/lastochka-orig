<?php

namespace Tests\Feature\Quantum;

use App\Models\Category;
use App\Models\Price;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Type;
use App\Services\Quantum\CategoryQuantumService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Пересчёт цены порции не теряет копейки.
 *
 * Цена за грамм хранилась с 4 знаками: 199.99 ₽/кг → 0.2000 ₽/г → 200.00 ₽/кг,
 * ручные 200 ₽ за 300 г → 200.01 ₽. Цена в копейках / 1000 укладывается в
 * 6 знаков точно; цена порции от сырой цены считается без промежуточного округления.
 */
class QuantumPriceKopecksTest extends TestCase
{
    use DatabaseTransactions;

    private Shop $shop;
    private CategoryQuantumService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        $this->shop = Shop::forceCreate(['title' => 'Копейки ' . uniqid(), 'code' => 'kopecks-' . uniqid()]);
        $this->service = app(CategoryQuantumService::class);
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    private function product(?int $quantumStep): Product
    {
        $category = Category::create([
            'title' => 'Cat ' . uniqid(),
            'quantum_step' => $quantumStep,
            'quantum_unit_type_id' => $quantumStep !== null ? Type::INFO_WEIGHT_GRAM : null,
            'is_active' => true,
        ]);
        $product = Product::create([
            'title' => 'P ' . uniqid(),
            'articul' => 'k-' . uniqid(),
            'unit_type_id' => Type::UNIT_FOOD,
            'info_weight_type_id' => Type::INFO_WEIGHT_KILOGRAM,
            'base_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM,
            'shop_id' => $this->shop->id,
        ]);
        $product->categories()->sync([$category->id]);
        Price::create([
            'product_id' => $product->id,
            'shop_id' => $this->shop->id,
            'type_id' => Type::PRICE_DEFAULT,
            'value' => 1,
            'step' => 1,
        ]);

        return $product->fresh();
    }

    private function price(Product $product): Price
    {
        return Price::where('product_id', $product->id)->where('shop_id', $this->shop->id)
            ->where('type_id', Type::PRICE_DEFAULT)->firstOrFail();
    }

    public function test_kilogram_price_keeps_kopecks(): void
    {
        $product = $this->product(null);

        $this->service->applyToProduct($product, $this->shop->id, 199.99, dispatchEvent: false);

        $this->assertSame(199.99, (float) $this->price($product)->value);
        $this->assertSame(199.99, (float) $this->price($product)->price_per_kg);
    }

    public function test_portion_price_is_computed_from_raw_price(): void
    {
        // 594.55 ₽/кг × 0.3 кг = 178.365 → 178.37
        $product = $this->product(300);

        $this->service->applyToProduct($product, $this->shop->id, 594.55, dispatchEvent: false);

        $this->assertSame(178.37, (float) $this->price($product)->value);
    }

    public function test_recalc_from_stored_price_keeps_kopecks(): void
    {
        $product = $this->product(null);
        $this->service->applyToProduct($product, $this->shop->id, 199.99, dispatchEvent: false);

        $this->service->applyToProduct($product->fresh(), $this->shop->id, null, dispatchEvent: false);

        $this->assertSame(199.99, (float) $this->price($product)->value);
    }

    public function test_manual_kilogram_price_is_saved_as_entered(): void
    {
        $product = $this->product(null);

        $this->service->applyManualPrice($product, $this->shop->id, 34.99, dispatchEvent: false);

        $this->assertSame(34.99, (float) $this->price($product)->value);
    }

    public function test_manual_portion_price_is_saved_as_entered(): void
    {
        $product = $this->product(300);

        $this->service->applyManualPrice($product, $this->shop->id, 200.00, dispatchEvent: false);

        $this->assertSame(200.0, (float) $this->price($product)->value);
    }
}
