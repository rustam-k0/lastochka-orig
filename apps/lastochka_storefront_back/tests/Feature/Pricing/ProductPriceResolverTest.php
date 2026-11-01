<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Store;
use App\Models\StoreProduct;
use App\Services\Catalog\StoreOfferContext;
use App\Services\Pricing\ProductPriceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\TogglesPriceTiersModule;
use Tests\TestCase;

final class ProductPriceResolverTest extends TestCase
{
    use RefreshDatabase;
    use TogglesPriceTiersModule;

    private const FROM_ONE = [
        ['min_quantity' => 1, 'price' => 279],
        ['min_quantity' => 2, 'price' => 249],
        ['min_quantity' => 5, 'price' => 199],
    ];

    private const FROM_TWO = [
        ['min_quantity' => 2, 'price' => 249],
        ['min_quantity' => 5, 'price' => 199],
    ];

    private function resolver(): ProductPriceResolver
    {
        return app(ProductPriceResolver::class);
    }

    /** Товар с ручной скидкой: обычная цена 269, зачёркнутая 309. */
    private function discountedProduct(array $attributes = []): Product
    {
        return Product::factory()->create(array_merge([
            'price' => 269,
            'price_old' => 309,
            'quantity' => 100,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
        ], $attributes));
    }

    public function test_flag_off_returns_exactly_legacy_prices(): void
    {
        $this->setPriceTiersModule(false);
        $product = $this->discountedProduct();
        $this->seedPriceTiers($product, (int) $product->store_id, self::FROM_ONE);

        $quote = $this->resolver()->quote($product, 5);

        $this->assertSame($product->getEffectivePrice(), $quote->price);
        $this->assertSame($product->getOriginalPrice(), $quote->priceOld);
        $this->assertFalse($quote->tierApplied);
        $this->assertSame([], $this->resolver()->tiersForApi($product));
        $this->assertNull($this->resolver()->snapshotFor($product, true));
    }

    public function test_product_without_ladder_returns_exactly_legacy_prices(): void
    {
        $this->setPriceTiersModule(true);
        $product = $this->discountedProduct();

        $quote = $this->resolver()->quote($product, 5);

        $this->assertSame(269.0, $quote->price);
        $this->assertSame(309.0, $quote->priceOld);
        $this->assertFalse($quote->tierApplied);
    }

    public function test_tier_beats_manual_discount_and_first_tier_is_the_reference(): void
    {
        $this->setPriceTiersModule(true);
        $product = $this->discountedProduct();
        $this->seedPriceTiers($product, (int) $product->store_id, self::FROM_ONE);

        $one = $this->resolver()->quote($product, 1);
        $this->assertSame(279.0, $one->price);
        $this->assertNull($one->priceOld, 'первая ступень: обычная позиция без зачёркнутой цены');
        $this->assertTrue($one->tierApplied);

        $three = $this->resolver()->quote($product, 3);
        $this->assertSame(249.0, $three->price);
        $this->assertSame(279.0, $three->priceOld);

        $five = $this->resolver()->quote($product, 5);
        $this->assertSame(199.0, $five->price);
        $this->assertSame(279.0, $five->priceOld);
    }

    public function test_tier_beats_active_promotion(): void
    {
        $this->setPriceTiersModule(true);
        $product = Product::factory()->create(['price' => 300, 'quantity' => 100, 'quantity_step' => 1]);
        Promotion::factory()->for($product->store)->create(['discount_percent' => 50, 'is_active' => true])
            ->products()->attach($product->id);
        $product = Product::findOrFail($product->id);
        $this->seedPriceTiers($product, (int) $product->store_id, [['min_quantity' => 1, 'price' => 290]]);

        $this->assertSame(150.0, $product->getEffectivePrice(), 'предусловие: акция 50% действует');

        $quote = $this->resolver()->quote($product, 1);

        $this->assertSame(290.0, $quote->price);
        $this->assertNull($quote->priceOld);
        $this->assertTrue($quote->tierApplied);
    }

    public function test_quantity_below_first_threshold_falls_back_to_legacy_prices(): void
    {
        $this->setPriceTiersModule(true);
        $product = $this->discountedProduct();
        $this->seedPriceTiers($product, (int) $product->store_id, self::FROM_TWO);

        $one = $this->resolver()->quote($product, 1);
        $this->assertSame(269.0, $one->price);
        $this->assertSame(309.0, $one->priceOld);
        $this->assertFalse($one->tierApplied);

        $two = $this->resolver()->quote($product, 2);
        $this->assertSame(249.0, $two->price);
        $this->assertSame(309.0, $two->priceOld, 'лесенка не с единицы: опорная = обычная цена без скидок');
        $this->assertTrue($two->tierApplied);
    }

    public function test_quantum_product_is_quoted_in_portions(): void
    {
        $this->setPriceTiersModule(true);
        $product = Product::factory()->create([
            'price' => 100, 'quantity' => 100000, 'unit_type' => 'kg',
            'quantity_step' => 200, 'measurement_unit' => 'gram',
        ]);
        $this->seedPriceTiers($product, (int) $product->store_id, [['min_quantity' => 2, 'price' => 450]]);

        $this->assertSame(100.0, $this->resolver()->quote($product, 9)->price);
        $this->assertSame(90.0, $this->resolver()->quote($product, 10)->price, 'от 2 кг = от 10 порций, 450 ₽/кг = 90 ₽ за 200 г');
        $this->assertSame(10.0, $this->resolver()->portionsFor($product, 2000));
        $this->assertSame(
            [['minQuantity' => 2000.0, 'price' => 90.0]],
            $this->resolver()->tiersForApi($product),
            'minQuantity в единицах quantity корзины (граммы), price за порцию'
        );
    }

    public function test_snapshot_for_carries_legacy_base_prices(): void
    {
        $this->setPriceTiersModule(true);
        $product = $this->discountedProduct();
        $this->seedPriceTiers($product, (int) $product->store_id, self::FROM_TWO);

        $snapshot = $this->resolver()->snapshotFor($product, false);

        $this->assertSame([
            'base_price' => 269.0,
            'base_price_old' => 309.0,
            'base_is_eligible' => false,
            'tiers' => [
                ['min_quantity' => 2.0, 'price' => 249.0],
                ['min_quantity' => 5.0, 'price' => 199.0],
            ],
        ], $snapshot->toArray());
    }

    public function test_is_tier_applied(): void
    {
        $this->setPriceTiersModule(true);
        $product = $this->discountedProduct();
        $this->seedPriceTiers($product, (int) $product->store_id, self::FROM_TWO);

        $this->assertFalse($this->resolver()->isTierApplied($product, 1));
        $this->assertTrue($this->resolver()->isTierApplied($product, 2));
        $this->assertFalse($this->resolver()->isTierApplied($product, 2, Store::factory()->create()->id));
    }

    // ==================== Сетевой каталог ====================

    public function test_network_mode_uses_ladder_of_the_offer_store(): void
    {
        $this->setPriceTiersModule(true, networkCatalog: true);

        $anchor = Store::factory()->create();
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $product = Product::factory()->create(['store_id' => $anchor->id, 'price' => 0, 'quantity' => 0, 'quantity_step' => 1]);

        foreach ([$storeA, $storeB] as $store) {
            StoreProduct::create(['store_id' => $store->id, 'product_id' => $product->id, 'price' => 300, 'quantity' => 10]);
        }
        $this->seedPriceTiers($product, $storeA->id, [['min_quantity' => 3, 'price' => 250]]);

        $inA = Product::with(StoreOfferContext::eagerLoad($storeA->id))->findOrFail($product->id);
        $inB = Product::with(StoreOfferContext::eagerLoad($storeB->id))->findOrFail($product->id);

        $this->assertSame(250.0, $this->resolver()->quote($inA, 3)->price);
        $this->assertSame(300.0, $this->resolver()->quote($inA, 3)->priceOld);
        $this->assertSame(300.0, $this->resolver()->quote($inB, 3)->price, 'в магазине B лесенки нет');

        // Без контекста магазина лесенку выбрать не из чего: прежние методы цены.
        $bare = Product::findOrFail($product->id);
        $this->assertSame($bare->getEffectivePrice(), $this->resolver()->quote($bare, 3)->price);
        $this->assertFalse($this->resolver()->quote($bare, 3)->tierApplied);
    }

    // ==================== Без N+1 ====================

    public function test_preload_products_loads_ladders_with_one_query(): void
    {
        $this->setPriceTiersModule(true);
        $store = Store::factory()->create();
        $products = Product::factory()->count(5)->for($store)->create(['price' => 100, 'quantity_step' => 1]);
        foreach ($products as $product) {
            $this->seedPriceTiers($product, $store->id, [['min_quantity' => 2, 'price' => 90]]);
        }

        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'product_price_tiers')) {
                $queries++;
            }
        });

        $this->resolver()->preloadProducts($products);
        foreach ($products as $product) {
            $this->assertSame(90.0, $this->resolver()->quote($product, 2)->price);
            $this->resolver()->tiersForApi($product);
        }

        $this->assertSame(1, $queries);
    }

    public function test_preload_does_nothing_when_flag_is_off(): void
    {
        $this->setPriceTiersModule(false);
        $products = Product::factory()->count(2)->create();

        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'product_price_tiers')) {
                $queries++;
            }
        });

        $this->resolver()->preloadProducts($products);
        $this->resolver()->preloadProducts('not iterable');
        $this->resolver()->quote($products[0], 3);

        $this->assertSame(0, $queries, 'при выключенном флаге таблица ступеней не читается');
    }

    // ==================== preloadCartItems() ====================

    public function test_preload_cart_items_loads_ladders_with_one_query_in_normal_mode(): void
    {
        $this->setPriceTiersModule(true);
        $store = Store::factory()->create();
        $cart = Cart::factory()->create();
        $products = Product::factory()->count(3)->for($store)->create(['price' => 100, 'quantity_step' => 1]);

        $items = $products->map(function (Product $product) use ($cart, $store) {
            $this->seedPriceTiers($product, $store->id, [['min_quantity' => 2, 'price' => 90]]);

            $item = CartItem::factory()->create([
                'cart_id' => $cart->id,
                'product_id' => $product->id,
                'store_id' => $store->id,
                'quantity' => 2,
            ]);

            return $item->load('product');
        });

        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'product_price_tiers')) {
                $queries++;
            }
        });

        $this->resolver()->preloadCartItems($items);

        foreach ($items as $item) {
            $this->assertSame(90.0, $this->resolver()->quote($item->product, 2)->price);
        }

        $this->assertSame(1, $queries);
    }

    /**
     * Сетевой каталог: store_id позиции — магазин ПОКУПКИ, отличный от
     * анкорного магазина товара. Лесенка заведена на магазин позиции; лесенка
     * на анкорный магазин (если бы её читали по ошибке) дала бы другую цену.
     */
    public function test_preload_cart_items_uses_ladder_of_the_item_store_in_network_mode(): void
    {
        $this->setPriceTiersModule(true, networkCatalog: true);

        $anchor = Store::factory()->create();
        $purchaseStore = Store::factory()->create();
        $product = Product::factory()->create(['store_id' => $anchor->id, 'price' => 0, 'quantity' => 0, 'quantity_step' => 1]);

        StoreProduct::create(['store_id' => $purchaseStore->id, 'product_id' => $product->id, 'price' => 300, 'quantity' => 10]);

        // Ловушка: лесенка анкорного магазина товара — если бы storeIdFor() ошибочно
        // читал products.store_id вместо store_id позиции, цена была бы отсюда.
        $this->seedPriceTiers($product, $anchor->id, [['min_quantity' => 2, 'price' => 1]]);
        $this->seedPriceTiers($product, $purchaseStore->id, [['min_quantity' => 2, 'price' => 250]]);

        $cart = Cart::factory()->create();
        $item = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'store_id' => $purchaseStore->id,
            'quantity' => 2,
        ])->load('product');

        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'product_price_tiers')) {
                $queries++;
            }
        });

        $this->resolver()->preloadCartItems([$item]);

        $offerLoaded = Product::with(StoreOfferContext::eagerLoad($purchaseStore->id))->findOrFail($product->id);
        $this->assertSame(250.0, $this->resolver()->quote($offerLoaded, 2)->price, 'котировка по лесенке магазина позиции, не анкорного магазина товара');

        $this->assertSame(1, $queries);
    }

    /**
     * Позиция без загруженной связи product (и позиция с удалённым товаром)
     * пропускается: метод не падает, лесенки не читает и НЕ лениво подгружает
     * product там, где связь не была загружена вызывающим кодом.
     */
    public function test_preload_cart_items_skips_items_without_loaded_product_and_does_not_lazy_load(): void
    {
        $this->setPriceTiersModule(true);
        $store = Store::factory()->create();
        $cart = Cart::factory()->create();

        $liveProduct = Product::factory()->for($store)->create(['price' => 100, 'quantity_step' => 1]);
        $this->seedPriceTiers($liveProduct, $store->id, [['min_quantity' => 2, 'price' => 90]]);

        $deletedProduct = Product::factory()->for($store)->create(['price' => 100, 'quantity_step' => 1]);
        $deletedProductId = $deletedProduct->id;
        $deletedProduct->delete();

        // Связь product намеренно не грузим — проверяем, что метод её не подгрузит сам.
        $itemWithoutRelation = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $liveProduct->id,
            'store_id' => $store->id,
            'quantity' => 2,
        ]);

        $itemDeletedProduct = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $deletedProductId,
            'store_id' => $store->id,
            'quantity' => 2,
        ])->load('product');

        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'product_price_tiers')) {
                $queries++;
            }
        });

        $this->resolver()->preloadCartItems([$itemWithoutRelation, $itemDeletedProduct]);

        $this->assertSame(0, $queries, 'позиции без товара пропускаются: лесенки не читаются');
        $this->assertFalse(
            $itemWithoutRelation->relationLoaded('product'),
            'метод не должен лениво подгружать product у позиции, где связь не была загружена'
        );
        $this->assertNull($itemDeletedProduct->product, 'удалённый (soft-deleted) товар не резолвится связью');
    }

    public function test_preload_cart_items_does_nothing_when_flag_is_off(): void
    {
        $this->setPriceTiersModule(false);
        $store = Store::factory()->create();
        $cart = Cart::factory()->create();
        $product = Product::factory()->for($store)->create(['price' => 100, 'quantity_step' => 1]);
        $item = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => 2,
        ])->load('product');

        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'product_price_tiers')) {
                $queries++;
            }
        });

        $this->resolver()->preloadCartItems([$item]);

        $this->assertSame(0, $queries, 'при выключенном флаге таблица ступеней не читается');
    }
}
