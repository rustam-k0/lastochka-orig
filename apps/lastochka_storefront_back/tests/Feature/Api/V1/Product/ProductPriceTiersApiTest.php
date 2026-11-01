<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Product;

use App\Models\Category;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\TogglesPriceTiersModule;
use Tests\TestCase;

final class ProductPriceTiersApiTest extends TestCase
{
    use RefreshDatabase;
    use TogglesPriceTiersModule;

    private const FROM_ONE = [
        ['min_quantity' => 1, 'price' => 279],
        ['min_quantity' => 2, 'price' => 249],
        ['min_quantity' => 5, 'price' => 199],
    ];

    private Store $store;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create(['is_active' => true]);
        $this->category = Category::factory()->forStore($this->store)->create(['is_active' => true]);
    }

    private function product(array $attributes = []): Product
    {
        return Product::factory()->for($this->store)->inCategory($this->category)->create(array_merge([
            'price' => 269,
            'price_old' => 309,
            'quantity' => 100,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
            'is_active' => true,
        ], $attributes));
    }

    public function test_card_returns_ladder_and_price_for_one_portion(): void
    {
        $this->setPriceTiersModule(true);
        $product = $this->product();
        $this->seedPriceTiers($product, $this->store->id, self::FROM_ONE);

        $response = $this->getJson("/api/v1/products/{$product->id}")->assertOk();

        $this->assertEquals([
            ['minQuantity' => 1, 'price' => 279],
            ['minQuantity' => 2, 'price' => 249],
            ['minQuantity' => 5, 'price' => 199],
        ], $response->json('data.priceTiers'));
        $this->assertEquals(279.0, (float) $response->json('data.price'), 'цена карточки = котировка для одной порции');
        $this->assertNull($response->json('data.priceOld'));
        $this->assertNull($response->json('data.discountPercent'));
    }

    public function test_ladder_starting_above_one_keeps_regular_card_price(): void
    {
        $this->setPriceTiersModule(true);
        $product = $this->product();
        $this->seedPriceTiers($product, $this->store->id, [['min_quantity' => 2, 'price' => 249]]);

        $response = $this->getJson("/api/v1/products/{$product->id}")->assertOk();

        $this->assertEquals(269.0, (float) $response->json('data.price'));
        $this->assertEquals(309.0, (float) $response->json('data.priceOld'));
        $this->assertEquals([['minQuantity' => 2, 'price' => 249]], $response->json('data.priceTiers'));
    }

    public function test_quantum_product_ladder_is_in_cart_quantity_units(): void
    {
        $this->setPriceTiersModule(true);
        $product = $this->product([
            'price' => 100, 'price_old' => null, 'quantity' => 100000,
            'unit_type' => 'kg', 'quantity_step' => 200, 'measurement_unit' => 'gram',
        ]);
        $this->seedPriceTiers($product, $this->store->id, [['min_quantity' => 2, 'price' => 450]]);

        $response = $this->getJson("/api/v1/products/{$product->id}")->assertOk();

        $this->assertEquals([['minQuantity' => 2000, 'price' => 90]], $response->json('data.priceTiers'));
        $this->assertSame(200, $response->json('data.quantityStep'));
        $this->assertEquals(100.0, (float) $response->json('data.price'));
    }

    /**
     * Финальное ревью (F6): порог 2 кг при шаге 300 г → round(2*1000/300, 3) =
     * 6.667 порции → 6.667*300 = 2000.1 г — но ступень реально включается
     * только с 7 порций = 2100 г. API обязан отдавать порог, округлённый
     * ВВЕРХ до кратного шагу количества (минимальное количество корзины, при
     * котором ступень реально срабатывает), а не дробный грамм.
     */
    public function test_ladder_threshold_not_multiple_of_step_rounds_up_to_step(): void
    {
        $this->setPriceTiersModule(true);
        $product = $this->product([
            'price' => 500, 'price_old' => null, 'quantity' => 100000,
            'unit_type' => 'kg', 'quantity_step' => 300, 'measurement_unit' => 'gram',
        ]);
        $this->seedPriceTiers($product, $this->store->id, [['min_quantity' => 2, 'price' => 450]]);

        $response = $this->getJson("/api/v1/products/{$product->id}")->assertOk();

        $this->assertEquals([['minQuantity' => 2100, 'price' => 135]], $response->json('data.priceTiers'));
    }

    public function test_listing_returns_ladders_and_loads_them_with_one_query(): void
    {
        $this->setPriceTiersModule(true);
        foreach (range(1, 4) as $i) {
            $this->seedPriceTiers($this->product(['price' => 100 + $i, 'price_old' => null]), $this->store->id, [
                ['min_quantity' => 3, 'price' => 90],
            ]);
        }

        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'product_price_tiers')) {
                $queries++;
            }
        });

        $response = $this->getJson("/api/v1/stores/{$this->store->id}/categories/{$this->category->id}/products")->assertOk();

        $this->assertCount(4, $response->json('data'));
        foreach ($response->json('data') as $row) {
            $this->assertEquals([['minQuantity' => 3, 'price' => 90]], $row['priceTiers']);
        }
        $this->assertSame(1, $queries, 'лесенки листинга грузятся одним запросом');
    }

    public function test_promotion_products_prefer_tier_over_promotion(): void
    {
        $this->setPriceTiersModule(true);
        $tiered = $this->product(['price' => 300, 'price_old' => null]);
        $plain = $this->product(['price' => 500, 'price_old' => null]);
        $this->seedPriceTiers($tiered, $this->store->id, [['min_quantity' => 1, 'price' => 290], ['min_quantity' => 3, 'price' => 250]]);

        $promotion = Promotion::factory()->create([
            'store_id' => $this->store->id, 'discount_percent' => 10, 'is_active' => true, 'slug' => 'tiers-'.uniqid(),
        ]);
        $promotion->products()->attach([$tiered->id, $plain->id]);

        $rows = collect($this->getJson("/api/v1/promotions/{$promotion->slug}/products")->assertOk()->json('data'))->keyBy('id');

        $this->assertEquals(290.0, (float) $rows[$tiered->id]['price'], 'ступень приоритетнее акции');
        $this->assertEquals(290.0, (float) $rows[$tiered->id]['priceOld']);
        $this->assertNull($rows[$tiered->id]['discountPercent']);
        $this->assertCount(2, $rows[$tiered->id]['priceTiers']);

        $this->assertEquals(450.0, (float) $rows[$plain->id]['price'], 'товар без лесенки: акция как раньше');
        $this->assertSame([], $rows[$plain->id]['priceTiers']);
    }

    public function test_cart_item_returns_ladder(): void
    {
        $this->setPriceTiersModule(true);
        $product = $this->product(['price' => 279, 'price_old' => null]);
        $this->seedPriceTiers($product, $this->store->id, self::FROM_ONE);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/cart/items', ['productId' => $product->id, 'quantity' => 2])->assertOk();

        $this->assertCount(3, $response->json('storeGroups.0.items.0.priceTiers'));
        $this->assertEquals(249.0, (float) $response->json('storeGroups.0.items.0.price'));
        $this->assertEquals(279.0, (float) $response->json('storeGroups.0.items.0.product.price'), 'карточка внутри позиции: цена за одну порцию');
    }

    public function test_flag_off_returns_empty_ladder_and_legacy_prices(): void
    {
        $this->setPriceTiersModule(false);
        $product = $this->product();
        $this->seedPriceTiers($product, $this->store->id, self::FROM_ONE);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/cart/items', ['productId' => $product->id, 'quantity' => 5])->assertOk();

        // Страж брифа Task 13: при выключенном модуле — ни одного запроса
        // к product_price_tiers ни на карточке, ни в листинге, ни в корзине.
        $queries = $this->priceTierTableQueries(function () use ($product): void {
            $card = $this->getJson("/api/v1/products/{$product->id}")->assertOk();
            $this->assertSame([], $card->json('data.priceTiers'));
            $this->assertEquals(269.0, (float) $card->json('data.price'));
            $this->assertEquals(309.0, (float) $card->json('data.priceOld'));

            $listing = $this->getJson("/api/v1/stores/{$this->store->id}/categories/{$this->category->id}/products")->assertOk();
            $this->assertSame([], $listing->json('data.0.priceTiers'));

            $cart = $this->getJson('/api/v1/cart')->assertOk();
            $this->assertSame([], $cart->json('storeGroups.0.items.0.priceTiers'));
            $this->assertEquals(269.0, (float) $cart->json('storeGroups.0.items.0.price'));
        });

        $this->assertSame(0, $queries, 'при выключенном флаге запросов к product_price_tiers нет ни на карточке, ни в листинге, ни в корзине');
    }
}
