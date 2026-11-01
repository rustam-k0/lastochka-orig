<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Models\Product;
use App\Models\Store;
use App\RabbitMQ\Transformers\ProductEventTransformer;
use App\Services\Pricing\PriceTierLadder;
use App\Services\Pricing\ProductTierLadderProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\Concerns\TogglesPriceTiersModule;
use Tests\TestCase;

final class ProductTierLadderProviderTest extends TestCase
{
    use RefreshDatabase;
    use TogglesPriceTiersModule;

    private function product(string $unit, int $step, array $attributes = []): Product
    {
        return Product::factory()->create(array_merge([
            'measurement_unit' => $unit,
            'quantity_step' => $step,
            'unit_type' => $unit === 'piece' ? 'piece' : 'kg',
        ], $attributes));
    }

    // ==================== Правило единиц ====================

    public function test_piece_product_is_not_converted(): void
    {
        $this->assertSame([1.0, 1.0], ProductTierLadderProvider::unitSizes($this->product('piece', 1)));
    }

    public function test_quantum_200_gram_product_converts_kilograms_to_portions(): void
    {
        $this->assertSame([1000.0, 200.0], ProductTierLadderProvider::unitSizes($this->product('gram', 200)));
        $this->assertSame([1000.0, 250.0], ProductTierLadderProvider::unitSizes($this->product('milliliter', 250)));
    }

    /**
     * Карточка, ещё не согласованная reconcileQuantumUnits(): единица kilogram
     * при шаге 500 г. Корзина уже делит количество на шаг, значит и лесенка
     * переводится по шагу.
     */
    public function test_kilogram_card_with_step_uses_the_step(): void
    {
        $this->assertSame([1000.0, 500.0], ProductTierLadderProvider::unitSizes($this->product('kilogram', 500)));
    }

    public function test_weight_product_without_quantum_is_identity(): void
    {
        $this->assertSame([1000.0, 1000.0], ProductTierLadderProvider::unitSizes($this->product('kilogram', 1)));
        $this->assertSame([1000.0, 1000.0], ProductTierLadderProvider::unitSizes($this->product('liter', 1)));
        $this->assertSame([1.0, 1.0], ProductTierLadderProvider::unitSizes($this->product('gram', 1)));
    }

    public function test_piece_product_sold_by_portions_divides_threshold_by_step(): void
    {
        $this->assertSame([1.0, 10.0], ProductTierLadderProvider::unitSizes($this->product('piece', 10)));
    }

    /**
     * Цена ступени квантового товара считается той же формулой, что цена
     * карточки в трансформере: priceForQuantum(цена за кг, шаг).
     */
    public function test_quantum_tier_price_matches_transformer_price_for_quantum(): void
    {
        $priceForQuantum = new ReflectionMethod(ProductEventTransformer::class, 'priceForQuantum');
        $expected = $priceForQuantum->invoke(app(ProductEventTransformer::class), 290.0, 500);

        $portions = PriceTierLadder::toPortions(
            PriceTierLadder::normalize([['min_quantity' => 1, 'price' => 290]]),
            ...ProductTierLadderProvider::unitSizes($this->product('gram', 500))
        );

        $this->assertSame(145.0, $expected);
        $this->assertSame($expected, $portions[0]['price']);
        $this->assertSame(2.0, $portions[0]['min_quantity'], '1 кг = 2 порции по 500 г');
    }

    // ==================== Лесенка в порциях ====================

    public function test_portion_ladder_for_piece_quantum_and_weight_products(): void
    {
        $provider = app(ProductTierLadderProvider::class);

        $piece = $this->product('piece', 1);
        $this->seedPriceTiers($piece, (int) $piece->store_id, [
            ['min_quantity' => 2, 'price' => 249], ['min_quantity' => 5, 'price' => 199],
        ]);
        $this->assertSame([
            ['min_quantity' => 2.0, 'price' => 249.0],
            ['min_quantity' => 5.0, 'price' => 199.0],
        ], $provider->portionLadder($piece, (int) $piece->store_id));

        $quantum = $this->product('gram', 200);
        $this->seedPriceTiers($quantum, (int) $quantum->store_id, [['min_quantity' => 2, 'price' => 500]]);
        $this->assertSame(
            [['min_quantity' => 10.0, 'price' => 100.0]],
            $provider->portionLadder($quantum, (int) $quantum->store_id),
            'от 2 кг по 500 ₽/кг = от 10 порций по 100 ₽ за 200 г'
        );

        $weight = $this->product('kilogram', 1);
        $this->seedPriceTiers($weight, (int) $weight->store_id, [['min_quantity' => 2.5, 'price' => 500]]);
        $this->assertSame(
            [['min_quantity' => 2.5, 'price' => 500.0]],
            $provider->portionLadder($weight, (int) $weight->store_id)
        );
    }

    public function test_ladder_is_scoped_to_store(): void
    {
        $product = $this->product('piece', 1);
        $otherStore = Store::factory()->create();
        $this->seedPriceTiers($product, $otherStore->id, [['min_quantity' => 2, 'price' => 90]]);

        $provider = app(ProductTierLadderProvider::class);

        $this->assertSame([], $provider->baseLadder($product->id, (int) $product->store_id));
        $this->assertCount(1, $provider->baseLadder($product->id, $otherStore->id));
    }

    // ==================== Пачечная загрузка ====================

    public function test_preload_loads_all_pairs_with_one_query_and_memoizes_misses(): void
    {
        $store = Store::factory()->create();
        $products = Product::factory()->count(4)->for($store)->create();
        $this->seedPriceTiers($products[0], $store->id, [['min_quantity' => 2, 'price' => 90]]);
        $this->seedPriceTiers($products[1], $store->id, [['min_quantity' => 3, 'price' => 80]]);

        $provider = app(ProductTierLadderProvider::class);

        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'product_price_tiers')) {
                $queries++;
            }
        });

        $provider->preload($products->map(fn (Product $p) => [$store->id, $p->id])->all());

        foreach ($products as $product) {
            $provider->baseLadder($product->id, $store->id);
        }
        $provider->preload($products->map(fn (Product $p) => [$store->id, $p->id])->all());

        $this->assertSame(1, $queries, 'лесенки всей пачки одним запросом, промахи тоже запоминаются');
        $this->assertCount(1, $provider->baseLadder($products[0]->id, $store->id));
        $this->assertSame([], $provider->baseLadder($products[3]->id, $store->id));
    }

    public function test_flush_drops_memo(): void
    {
        $product = $this->product('piece', 1);
        $provider = app(ProductTierLadderProvider::class);

        $this->assertSame([], $provider->baseLadder($product->id, (int) $product->store_id));

        $this->seedPriceTiers($product, (int) $product->store_id, [['min_quantity' => 2, 'price' => 90]]);

        $this->assertCount(1, $provider->baseLadder($product->id, (int) $product->store_id));
    }

    public function test_provider_is_shared_within_request_scope(): void
    {
        $this->assertSame(app(ProductTierLadderProvider::class), app(ProductTierLadderProvider::class));
    }
}
