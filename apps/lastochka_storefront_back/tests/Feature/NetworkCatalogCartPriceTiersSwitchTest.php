<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreProduct;
use App\Models\User;
use App\Services\Pricing\ProductTierLadderProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TogglesPriceTiersModule;
use Tests\TestCase;

/**
 * Цены от количества при переключении корзины на магазин (POST /api/v1/cart/store):
 * перепривязанная и слитая позиция котируются по лесенке ЦЕЛЕВОГО магазина.
 */
final class NetworkCatalogCartPriceTiersSwitchTest extends TestCase
{
    use RefreshDatabase;
    use TogglesPriceTiersModule;

    private Store $storeA;

    private Store $storeB;

    private Product $product;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $anchor = Store::factory()->create(['code' => 'tiers-switch-anchor', 'is_active' => true]);
        $this->storeA = Store::factory()->create(['code' => 'tiers-switch-a', 'is_active' => true]);
        $this->storeB = Store::factory()->create(['code' => 'tiers-switch-b', 'is_active' => true]);

        $this->setPriceTiersModule(true, networkCatalog: true);

        $this->product = Product::factory()->create([
            'store_id' => $anchor->id, 'is_active' => true, 'price' => 0, 'quantity' => 0, 'quantity_step' => 1,
        ]);
        StoreProduct::create(['store_id' => $this->storeA->id, 'product_id' => $this->product->id, 'price' => 279, 'quantity' => 50]);
        StoreProduct::create(['store_id' => $this->storeB->id, 'product_id' => $this->product->id, 'price' => 300, 'quantity' => 50]);

        $this->seedPriceTiers($this->product, $this->storeB->id, [
            ['min_quantity' => 1, 'price' => 300],
            ['min_quantity' => 5, 'price' => 250],
        ]);

        $this->user = User::factory()->create();
    }

    private function addTo(Store $store, int $quantity): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/cart/items', [
            'productId' => $this->product->id,
            'quantity' => $quantity,
            'storeId' => $store->id,
        ])->assertSuccessful();
    }

    public function test_rebind_quotes_by_target_store_ladder(): void
    {
        $this->addTo($this->storeA, 5);
        $this->assertDatabaseHas('cart_items', ['store_id' => $this->storeA->id, 'price' => 279, 'price_old' => null]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/cart/store', ['storeId' => $this->storeB->id]);

        $response->assertOk();
        $this->assertEquals(250.0, (float) $response->json('storeGroups.0.items.0.price'));
        $this->assertEquals(300.0, (float) $response->json('storeGroups.0.items.0.priceOld'));
        $this->assertDatabaseHas('cart_items', ['store_id' => $this->storeB->id, 'quantity' => 5, 'price' => 250, 'price_old' => 300]);
    }

    public function test_merge_requotes_target_item_by_total_quantity(): void
    {
        $this->addTo($this->storeB, 3);
        $this->addTo($this->storeA, 2);
        $this->assertDatabaseHas('cart_items', ['store_id' => $this->storeB->id, 'quantity' => 3, 'price' => 300]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/cart/store', ['storeId' => $this->storeB->id]);

        $response->assertOk();
        $this->assertSame(1, CartItem::where('product_id', $this->product->id)->count());
        $this->assertDatabaseHas('cart_items', ['store_id' => $this->storeB->id, 'quantity' => 5, 'price' => 250, 'price_old' => 300]);
    }

    /**
     * Позиции без оффера в целевом магазине удаляются и попадают в
     * removedItems (в форме CartItem, {@see \App\Http\Resources\Api\V1\CartItemResource})
     * с лесенкой их исходного магазина. Без предзагрузки каждая такая позиция
     * тянула бы свою лесенку отдельным запросом (N+1); страж сравнивает число
     * запросов к product_price_tiers за весь запрос при 1 и при 3 удаляемых
     * позициях — оно не должно расти вместе с их числом.
     */
    public function test_removed_items_load_tier_ladders_with_bounded_queries(): void
    {
        $productAnchor = Store::factory()->create(['code' => 'tiers-switch-removed-anchor', 'is_active' => true]);
        $targetStore = Store::factory()->create(['code' => 'tiers-switch-removed-target', 'is_active' => true]);

        $queriesForRemovedCount = function (int $itemsCount) use ($productAnchor, $targetStore): int {
            $sourceStore = Store::factory()->create(['is_active' => true]);
            $user = User::factory()->create();

            for ($i = 0; $i < $itemsCount; $i++) {
                $product = Product::factory()->create([
                    'store_id' => $productAnchor->id, 'is_active' => true, 'price' => 0, 'quantity' => 0, 'quantity_step' => 1,
                ]);
                // Оффер только в исходном магазине — в целевом его нет, позиция будет удалена.
                StoreProduct::create(['store_id' => $sourceStore->id, 'product_id' => $product->id, 'price' => 100 + $i, 'quantity' => 10]);
                $this->seedPriceTiers($product, $sourceStore->id, [['min_quantity' => 1, 'price' => 100 + $i]]);

                $this->actingAs($user)->postJson('/api/v1/cart/items', [
                    'productId' => $product->id, 'quantity' => 1, 'storeId' => $sourceStore->id,
                ])->assertSuccessful();
            }

            // В реальном HTTP-цикле (не Octane) каждый запрос получает свежий
            // scoped-инстанс без прогретого мемо-кеша лесенок; add-to-cart
            // выше в этом же тестовом процессе делят контейнер с switchStore,
            // поэтому кеш нужно сбросить явно перед замером, иначе N+1 замера
            // не увидит (лесенки уже лежат в памяти после добавления в корзину).
            app(ProductTierLadderProvider::class)->flush();

            return $this->priceTierTableQueries(function () use ($user, $targetStore, $itemsCount): void {
                $this->actingAs($user)->postJson('/api/v1/cart/store', ['storeId' => $targetStore->id])
                    ->assertOk()
                    ->assertJsonCount($itemsCount, 'removedItems');
            });
        };

        $queriesForOne = $queriesForRemovedCount(1);
        $queriesForThree = $queriesForRemovedCount(3);

        $this->assertSame($queriesForOne, $queriesForThree, 'запросы к лесенкам удаляемых позиций не растут с их числом');
        $this->assertLessThanOrEqual(1, $queriesForThree, 'лесенки удаляемых позиций грузятся одним запросом');
    }

    /**
     * Финальное ревью (F2): preloadCartItems() в switchItems() кладёт пары
     * [ТЕКУЩИЙ store_id позиции, product_id], а rebind()/mergeInto() котируют
     * по ЦЕЛЕВОМУ магазину — промах мемо на каждую перепривязанную или
     * слитую позицию. Страж повторяет паттерн
     * test_removed_items_load_tier_ladders_with_bounded_queries: «1 позиция
     * vs 3 позиции», число запросов к product_price_tiers не растёт.
     */
    public function test_rebind_and_merge_load_target_store_ladders_with_bounded_queries(): void
    {
        $productAnchor = Store::factory()->create(['code' => 'tiers-switch-rebind-anchor', 'is_active' => true]);
        $targetStore = Store::factory()->create(['code' => 'tiers-switch-rebind-target', 'is_active' => true]);

        $queriesForCount = function (int $productsCount) use ($productAnchor, $targetStore): int {
            $sourceStoreA = Store::factory()->create(['is_active' => true]);
            $sourceStoreB = Store::factory()->create(['is_active' => true]);
            $user = User::factory()->create();

            for ($i = 0; $i < $productsCount; $i++) {
                $product = Product::factory()->create([
                    'store_id' => $productAnchor->id, 'is_active' => true, 'price' => 0, 'quantity' => 0, 'quantity_step' => 1,
                ]);
                // Оффер в обоих исходных магазинах и в целевом — позиция
                // перепривязывается/сливается, а не удаляется.
                StoreProduct::create(['store_id' => $sourceStoreA->id, 'product_id' => $product->id, 'price' => 100 + $i, 'quantity' => 10]);
                StoreProduct::create(['store_id' => $sourceStoreB->id, 'product_id' => $product->id, 'price' => 100 + $i, 'quantity' => 10]);
                StoreProduct::create(['store_id' => $targetStore->id, 'product_id' => $product->id, 'price' => 90 + $i, 'quantity' => 10]);
                $this->seedPriceTiers($product, $targetStore->id, [['min_quantity' => 1, 'price' => 90 + $i]]);

                // Один и тот же товар кладём в корзину из ДВУХ разных
                // исходных (не целевых) магазинов: первая позиция
                // перепривяжется (rebind), вторая с тем же ключом
                // «product_id/добавки» сольётся в неё (mergeInto).
                $this->actingAs($user)->postJson('/api/v1/cart/items', [
                    'productId' => $product->id, 'quantity' => 1, 'storeId' => $sourceStoreA->id,
                ])->assertSuccessful();
                $this->actingAs($user)->postJson('/api/v1/cart/items', [
                    'productId' => $product->id, 'quantity' => 1, 'storeId' => $sourceStoreB->id,
                ])->assertSuccessful();
            }

            app(ProductTierLadderProvider::class)->flush();

            return $this->priceTierTableQueries(function () use ($user, $targetStore): void {
                $this->actingAs($user)->postJson('/api/v1/cart/store', ['storeId' => $targetStore->id])
                    ->assertOk()
                    ->assertJsonCount(0, 'removedItems');
            });
        };

        $queriesForOne = $queriesForCount(1);
        $queriesForThree = $queriesForCount(3);

        $this->assertSame($queriesForOne, $queriesForThree, 'запросы к лесенкам целевого магазина не растут вместе с числом перепривязанных/слитых позиций');
        $this->assertLessThanOrEqual(2, $queriesForThree, 'лесенки целевого магазина для перепривязки и слияния грузятся батчем, не по позиции');
    }

    /**
     * Финальное ревью (F8): условие новой ветки в mergeInto() было только
     * enabled() — слияние переписывало price/price_old товару БЕЗ лесенки,
     * хотя до фичи слияние цену вообще не трогало (её пересчитывает выдача
     * GET /cart). Целевая позиция намеренно сделана «устаревшей» (цена
     * отличается от текущего оффера): если слияние её перекотирует, цена
     * поменяется на цену оффера, хотя должна остаться прежней — пересчитает
     * следующий GET /cart, не switchStore.
     */
    public function test_merge_does_not_rewrite_price_for_product_without_ladder(): void
    {
        $anchor = Store::factory()->create(['code' => 'tiers-merge-no-ladder-anchor', 'is_active' => true]);
        $sourceStore = Store::factory()->create(['code' => 'tiers-merge-no-ladder-source', 'is_active' => true]);
        $targetStore = Store::factory()->create(['code' => 'tiers-merge-no-ladder-target', 'is_active' => true]);
        $user = User::factory()->create();

        $product = Product::factory()->create([
            'store_id' => $anchor->id, 'is_active' => true, 'price' => 0, 'quantity' => 0, 'quantity_step' => 1,
        ]);
        StoreProduct::create(['store_id' => $sourceStore->id, 'product_id' => $product->id, 'price' => 120, 'quantity' => 10]);
        StoreProduct::create(['store_id' => $targetStore->id, 'product_id' => $product->id, 'price' => 100, 'quantity' => 10]);
        // Лесенки у товара НЕТ ни в одном магазине.

        $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId' => $product->id, 'quantity' => 1, 'storeId' => $targetStore->id,
        ])->assertSuccessful();
        $this->actingAs($user)->postJson('/api/v1/cart/items', [
            'productId' => $product->id, 'quantity' => 1, 'storeId' => $sourceStore->id,
        ])->assertSuccessful();

        // Позиция целевого магазина «устарела»: цена оффера сейчас 100, а
        // снапшот позиции — 999 (как будто оффер подорожал уже ПОСЛЕ
        // добавления в корзину). Слияние не должно её трогать.
        CartItem::where('store_id', $targetStore->id)->where('product_id', $product->id)
            ->update(['price' => 999, 'price_old' => null]);

        $response = $this->actingAs($user)->postJson('/api/v1/cart/store', ['storeId' => $targetStore->id]);

        $response->assertOk();
        $this->assertSame(1, CartItem::where('product_id', $product->id)->count(), 'позиции слились в одну');
        // Слияние без лесенки не переписывает price/price_old — это делает GET /cart.
        $this->assertDatabaseHas('cart_items', [
            'store_id' => $targetStore->id, 'quantity' => 2, 'price' => 999, 'price_old' => null,
        ]);
    }

    /**
     * Остатки ревью (R3): CartStoreOffers::offerMap() для предзагрузки
     * лесенок целевого магазина вызывался без гейта флага price_tiers — на
     * форках, где модуль выключен (а network_catalog включён), каждый
     * POST /cart/store делал лишний запрос к store_products. При выключенном
     * модуле должно быть на один запрос к store_products меньше, чем при
     * включённом (offerMap() не вызывается), и ни одного к product_price_tiers.
     */
    public function test_offer_map_preload_is_skipped_when_price_tiers_disabled(): void
    {
        $setUpScenario = function () {
            $anchor = Store::factory()->create(['is_active' => true]);
            $sourceStore = Store::factory()->create(['is_active' => true]);
            $targetStore = Store::factory()->create(['is_active' => true]);
            $user = User::factory()->create();

            $product = Product::factory()->create([
                'store_id' => $anchor->id, 'is_active' => true, 'price' => 0, 'quantity' => 0, 'quantity_step' => 1,
            ]);
            StoreProduct::create(['store_id' => $sourceStore->id, 'product_id' => $product->id, 'price' => 100, 'quantity' => 10]);
            StoreProduct::create(['store_id' => $targetStore->id, 'product_id' => $product->id, 'price' => 90, 'quantity' => 10]);

            $this->actingAs($user)->postJson('/api/v1/cart/items', [
                'productId' => $product->id, 'quantity' => 1, 'storeId' => $sourceStore->id,
            ])->assertSuccessful();

            return [$user, $targetStore];
        };

        $this->setPriceTiersModule(true, networkCatalog: true);
        [$userOn, $targetStoreOn] = $setUpScenario();
        $storeProductQueriesOn = $this->tableQueries('store_products', function () use ($userOn, $targetStoreOn): void {
            $this->actingAs($userOn)->postJson('/api/v1/cart/store', ['storeId' => $targetStoreOn->id])->assertOk();
        });

        $this->setPriceTiersModule(false, networkCatalog: true);
        [$userOff, $targetStoreOff] = $setUpScenario();
        $tierQueriesOff = 0;
        $storeProductQueriesOff = $this->tableQueries('store_products', function () use (&$tierQueriesOff, $userOff, $targetStoreOff): void {
            $tierQueriesOff = $this->priceTierTableQueries(function () use ($userOff, $targetStoreOff): void {
                $this->actingAs($userOff)->postJson('/api/v1/cart/store', ['storeId' => $targetStoreOff->id])->assertOk();
            });
        });

        $this->assertSame(0, $tierQueriesOff, 'при выключенном price_tiers ни одного запроса к product_price_tiers');
        $this->assertSame(
            $storeProductQueriesOn - 1,
            $storeProductQueriesOff,
            'при выключенном price_tiers offerMap() не вызывается — на один запрос к store_products меньше'
        );
    }
}
