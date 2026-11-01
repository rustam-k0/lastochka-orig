<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Actions\Order\CreateOrderAction;
use App\DTOs\Order\CreateOrderData;
use App\Enums\ShippingMethod;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreProduct;
use App\Models\User;
use App\Services\Pickup\PickupSlotService;
use App\Services\Pricing\CartItemTierPricing;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\TogglesPriceTiersModule;
use Tests\TestCase;

final class CreateOrderPriceTiersTest extends TestCase
{
    use RefreshDatabase;
    use TogglesPriceTiersModule;

    private const FROM_ONE = [
        ['min_quantity' => 1, 'price' => 279],
        ['min_quantity' => 2, 'price' => 249],
        ['min_quantity' => 5, 'price' => 199],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // Самовывоз читает слоты от now(): фиксируем утро (как CreateOrderWeightedQuantityTest).
        Carbon::setTestNow(Carbon::parse('2026-07-02 08:00:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pickupStore(array $attributes = []): Store
    {
        return Store::factory()->create(array_merge([
            'is_active' => true,
            'is_pickup_enabled' => true,
            'is_open_24_7' => true,
            'slot_duration' => 60,
            'min_pickup_order_amount' => 0,
            'min_order_amount' => 0,
            'pickup_payment_methods' => ['cash', 'card'],
            'is_bonus_enabled' => false,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ], $attributes));
    }

    private function putInCart(User $user, Store $store, Product $product, int $quantity, float $snapshotPrice): CartItem
    {
        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);

        return CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => $quantity,
            'price' => $snapshotPrice,
            'price_old' => null,
        ]);
    }

    private function createPickupOrder(User $user, Store $store): Order
    {
        $slots = app(PickupSlotService::class)->getSlots($store, now()->format('Y-m-d'), null);
        $this->assertNotEmpty($slots);

        $result = app(CreateOrderAction::class)->execute(new CreateOrderData(
            user: $user,
            storeId: $store->id,
            address: null,
            deliverySlotId: $slots[0]['id'],
            shippingMethod: ShippingMethod::Pickup,
            paymentType: 'cash',
        ));

        return Order::findOrFail($result['orderId']);
    }

    public function test_order_item_is_requoted_and_gets_ladder_snapshot(): void
    {
        $this->setPriceTiersModule(true);
        $user = User::factory()->create();
        $store = $this->pickupStore();
        $product = Product::factory()->for($store)->create(['price' => 279, 'quantity' => 100, 'quantity_step' => 1, 'measurement_unit' => 'piece']);
        $this->seedPriceTiers($product, $store->id, self::FROM_ONE);

        // Снапшот корзины устарел (279 за штуку): оформление обязано перекотировать.
        $this->putInCart($user, $store, $product, 5, 279);

        $order = $this->createPickupOrder($user, $store);
        $item = $order->items()->firstOrFail();

        $this->assertSame(199.0, (float) $item->price);
        $this->assertSame(279.0, (float) $item->price_old);
        $this->assertSame(995.0, (float) $item->total);
        $this->assertFalse($item->eligible_for_bonus, 'ступень дешевле опорной: позиция со скидкой');
        // assertEquals: MySQL JSON не сохраняет порядок ключей.
        $this->assertEquals([
            'base_price' => 279.0,
            'base_price_old' => null,
            'base_is_eligible' => true,
            'tiers' => [
                ['min_quantity' => 1.0, 'price' => 279.0],
                ['min_quantity' => 2.0, 'price' => 249.0],
                ['min_quantity' => 5.0, 'price' => 199.0],
            ],
        ], $item->price_tiers);
        $this->assertNull($item->price_tiers['base_price_old']);
        $this->assertSame(995.0, (float) $order->subtotal);
        $this->assertSame(995.0, (float) $order->total);

        // Инвариант чека: цена × порции = сумма строки.
        $this->assertSame((float) $item->total, round((float) $item->price * 5, 2));
    }

    public function test_first_tier_item_is_regular_and_eligible(): void
    {
        $this->setPriceTiersModule(true);
        $user = User::factory()->create();
        $store = $this->pickupStore();
        $product = Product::factory()->for($store)->create(['price' => 269, 'price_old' => 309, 'quantity' => 100, 'quantity_step' => 1]);
        $this->seedPriceTiers($product, $store->id, self::FROM_ONE);
        $this->putInCart($user, $store, $product, 1, 269);

        $item = $this->createPickupOrder($user, $store)->items()->firstOrFail();

        $this->assertSame(279.0, (float) $item->price, 'ступень приоритетнее ручной скидки');
        $this->assertNull($item->price_old);
        $this->assertTrue($item->eligible_for_bonus);
        $this->assertSame(269.0, (float) $item->price_tiers['base_price']);
        $this->assertSame(309.0, (float) $item->price_tiers['base_price_old']);
        $this->assertFalse($item->price_tiers['base_is_eligible'], 'обычная цена товара со скидкой');
    }

    public function test_quantity_below_first_threshold_uses_regular_price_and_keeps_snapshot(): void
    {
        $this->setPriceTiersModule(true);
        $user = User::factory()->create();
        $store = $this->pickupStore();
        $product = Product::factory()->for($store)->create(['price' => 279, 'quantity' => 100, 'quantity_step' => 1]);
        $this->seedPriceTiers($product, $store->id, [['min_quantity' => 3, 'price' => 250]]);
        $this->putInCart($user, $store, $product, 2, 279);

        $item = $this->createPickupOrder($user, $store)->items()->firstOrFail();

        $this->assertSame(279.0, (float) $item->price);
        $this->assertNull($item->price_old);
        $this->assertSame(558.0, (float) $item->total);
        $this->assertTrue($item->eligible_for_bonus);
        $this->assertNotNull($item->price_tiers, 'снапшот нужен, чтобы после сборки на 3 шт сработала ступень');
    }

    public function test_quantum_product_snapshot_is_in_portions(): void
    {
        $this->setPriceTiersModule(true);
        $user = User::factory()->create();
        $store = $this->pickupStore();
        $product = Product::factory()->for($store)->create([
            'price' => 100, 'quantity' => 1_000_000, 'unit_type' => 'kg',
            'quantity_step' => 200, 'measurement_unit' => 'gram',
        ]);
        $this->seedPriceTiers($product, $store->id, [['min_quantity' => 2, 'price' => 450]]);
        $this->putInCart($user, $store, $product, 2000, 100);

        $item = $this->createPickupOrder($user, $store)->items()->firstOrFail();

        $this->assertSame(2000, (int) $item->quantity);
        $this->assertSame(90.0, (float) $item->price);
        $this->assertSame(100.0, (float) $item->price_old);
        $this->assertSame(900.0, (float) $item->total, '10 порций по 90 ₽');
        $this->assertEquals([['min_quantity' => 10.0, 'price' => 90.0]], $item->price_tiers['tiers']);
    }

    public function test_product_without_ladder_keeps_cart_snapshot_and_has_no_snapshot(): void
    {
        $this->setPriceTiersModule(true);
        $user = User::factory()->create();
        $store = $this->pickupStore();
        $product = Product::factory()->for($store)->create(['price' => 300, 'quantity' => 100, 'quantity_step' => 1]);
        $this->putInCart($user, $store, $product, 2, 280);

        $item = $this->createPickupOrder($user, $store)->items()->firstOrFail();

        $this->assertSame(280.0, (float) $item->price, 'без лесенки цена берётся из снапшота корзины, как раньше');
        $this->assertNull($item->price_tiers);
    }

    public function test_flag_off_order_is_created_as_before(): void
    {
        $this->setPriceTiersModule(false);
        $user = User::factory()->create();
        $store = $this->pickupStore();
        $product = Product::factory()->for($store)->create(['price' => 279, 'quantity' => 100, 'quantity_step' => 1]);
        $this->seedPriceTiers($product, $store->id, self::FROM_ONE);
        $this->putInCart($user, $store, $product, 5, 279);

        $order = null;
        $queries = $this->priceTierTableQueries(function () use (&$order, $user, $store): void {
            $order = $this->createPickupOrder($user, $store);
        });
        $item = $order->items()->firstOrFail();

        $this->assertSame(0, $queries, 'при выключенном флаге оформление не обращается к таблице ступеней');
        $this->assertSame(279.0, (float) $item->price);
        $this->assertSame(1395.0, (float) $item->total);
        $this->assertNull($item->price_tiers, 'при выключенном флаге снапшот не пишется');
        $this->assertSame(1395.0, (float) $order->subtotal);
    }

    public function test_network_mode_takes_ladder_and_base_price_from_purchase_store_offer(): void
    {
        $this->setPriceTiersModule(true, networkCatalog: true);
        $user = User::factory()->create();
        $anchor = Store::factory()->create(['code' => 'tiers-order-anchor']);
        $store = $this->pickupStore(['code' => 'tiers-order-shop']);
        $product = Product::factory()->create(['store_id' => $anchor->id, 'price' => 0, 'quantity' => 0, 'quantity_step' => 1]);
        StoreProduct::create(['store_id' => $store->id, 'product_id' => $product->id, 'price' => 300, 'price_old' => 350, 'quantity' => 50]);
        $this->seedPriceTiers($product, $store->id, [['min_quantity' => 3, 'price' => 250]]);
        $this->putInCart($user, $store, $product, 3, 300);

        $item = $this->createPickupOrder($user, $store)->items()->firstOrFail();

        $this->assertSame(250.0, (float) $item->price);
        $this->assertSame(350.0, (float) $item->price_old, 'опорная цена: обычная цена оффера без скидок');
        $this->assertSame(750.0, (float) $item->total);
        $this->assertSame(300.0, (float) $item->price_tiers['base_price']);
        $this->assertSame(350.0, (float) $item->price_tiers['base_price_old']);
        $this->assertFalse($item->price_tiers['base_is_eligible']);
    }

    /**
     * Ревью фикс-раунд 1: pricingProduct() дёргал store_products по запросу
     * на позицию — оффер магазина покупки не предзагружался. При включённых
     * одновременно network_catalog и price_tiers (боевая конфигурация
     * mendev) requote() N тарифицируемых позиций давал N запросов вместо
     * одного пачечного (та же оптимизация, что уже применена к лесенкам
     * через ProductPriceResolver::preloadCartItems()).
     */
    public function test_network_mode_requote_batches_store_offers_for_multiple_items(): void
    {
        $this->setPriceTiersModule(true, networkCatalog: true);
        $user = User::factory()->create();
        $anchor = Store::factory()->create(['code' => 'tiers-batch-anchor']);
        $store = $this->pickupStore(['code' => 'tiers-batch-shop']);
        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);

        foreach (range(1, 3) as $i) {
            $product = Product::factory()->create([
                'store_id' => $anchor->id, 'price' => 0, 'quantity' => 0,
                'quantity_step' => 1, 'sku' => "tiers-batch-{$i}",
            ]);
            StoreProduct::create(['store_id' => $store->id, 'product_id' => $product->id, 'price' => 300, 'price_old' => 350, 'quantity' => 50]);
            $this->seedPriceTiers($product, $store->id, [['min_quantity' => 3, 'price' => 250]]);

            CartItem::factory()->for($cart)->for($product)->create([
                'store_id' => $store->id,
                'quantity' => 3,
                'price' => 300,
                'price_old' => null,
            ]);
        }

        $cartItems = CartItem::where('cart_id', $cart->id)
            ->where('store_id', $store->id)
            ->with(['product', 'supplements.values'])
            ->get();

        $queries = $this->tableQueries('store_products', function () use ($cartItems): void {
            app(CartItemTierPricing::class)->requote($cartItems);
        });

        $this->assertSame(1, $queries, 'офферы магазина для перекотировки грузятся одним пачечным запросом, не по одному на позицию');
    }

    /**
     * Ревью фикс-раунд 2: preloadOffers() кэшировал только НАЙДЕННЫЕ офферы —
     * пара «лесенка есть, валидного оффера нет» не попадала в кеш вообще, и
     * pricingProduct() при промахе падал обратно на поштучный offerFor().
     * N+1 возвращался именно для позиций без оффера (is_active=false / price=0).
     */
    public function test_network_mode_requote_batches_missing_offers_too(): void
    {
        $this->setPriceTiersModule(true, networkCatalog: true);
        $user = User::factory()->create();
        $anchor = Store::factory()->create(['code' => 'tiers-missing-anchor']);
        $store = $this->pickupStore(['code' => 'tiers-missing-shop']);
        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);

        // Одна позиция с валидным оффером...
        $withOffer = Product::factory()->create([
            'store_id' => $anchor->id, 'price' => 0, 'quantity' => 0,
            'quantity_step' => 1, 'sku' => 'tiers-missing-with-offer',
        ]);
        StoreProduct::create(['store_id' => $store->id, 'product_id' => $withOffer->id, 'price' => 300, 'price_old' => 350, 'quantity' => 50]);
        $this->seedPriceTiers($withOffer, $store->id, [['min_quantity' => 3, 'price' => 250]]);
        CartItem::factory()->for($cart)->for($withOffer)->create([
            'store_id' => $store->id, 'quantity' => 3, 'price' => 300, 'price_old' => null,
        ]);

        // ...и минимум две позиции БЕЗ валидного оффера (лесенка при этом заведена):
        // одна с is_active=false, другая с price=0 — оба условия, которые offerMap()/offerFor()
        // отфильтровывают как невалидный оффер.
        $missingOfferAttrs = [
            ['is_active' => false, 'price' => 300],
            ['is_active' => true, 'price' => 0],
        ];
        foreach ($missingOfferAttrs as $i => $offerAttrs) {
            $product = Product::factory()->create([
                'store_id' => $anchor->id, 'price' => 0, 'quantity' => 0,
                'quantity_step' => 1, 'sku' => "tiers-missing-{$i}",
            ]);
            StoreProduct::create(array_merge([
                'store_id' => $store->id, 'product_id' => $product->id, 'price_old' => null, 'quantity' => 50,
            ], $offerAttrs));
            $this->seedPriceTiers($product, $store->id, [['min_quantity' => 3, 'price' => 250]]);
            CartItem::factory()->for($cart)->for($product)->create([
                'store_id' => $store->id, 'quantity' => 3, 'price' => 300, 'price_old' => null,
            ]);
        }

        $cartItems = CartItem::where('cart_id', $cart->id)
            ->where('store_id', $store->id)
            ->with(['product', 'supplements.values'])
            ->get();

        $queries = $this->tableQueries('store_products', function () use ($cartItems): void {
            app(CartItemTierPricing::class)->requote($cartItems);
        });

        $this->assertSame(1, $queries, 'офферы (включая отсутствующие) грузятся одним запросом, не по одному на позицию без оффера');
    }

    /** @return list<string> SQL всех INSERT в order_items за время $callback */
    private function orderItemInsertSql(callable $callback): array
    {
        $inserts = [];

        DB::listen(function ($query) use (&$inserts): void {
            if (str_contains(mb_strtolower($query->sql), 'insert') && str_contains($query->sql, 'order_items')) {
                $inserts[] = $query->sql;
            }
        });

        $callback();

        return $inserts;
    }

    /**
     * Финальное ревью (F1): колонка price_tiers должна попадать в INSERT
     * позиции ТОЛЬКО когда снапшот есть — иначе она безусловно уходит в
     * запрос на выключенном флаге и на форках, где миграция ещё не накатилась
     * (CI: git pull → composer install → migrate --force — окно между шагами
     * ловит Unknown column).
     */
    public function test_order_item_insert_omits_price_tiers_column_when_flag_off(): void
    {
        $this->setPriceTiersModule(false);
        $user = User::factory()->create();
        $store = $this->pickupStore();
        $product = Product::factory()->for($store)->create(['price' => 279, 'quantity' => 100, 'quantity_step' => 1]);
        $this->seedPriceTiers($product, $store->id, self::FROM_ONE);
        $this->putInCart($user, $store, $product, 2, 279);

        $inserts = $this->orderItemInsertSql(function () use ($user, $store): void {
            $this->createPickupOrder($user, $store);
        });

        $this->assertNotEmpty($inserts, 'предусловие: позиция действительно создаётся INSERT-ом');
        foreach ($inserts as $sql) {
            $this->assertStringNotContainsString('price_tiers', $sql);
        }
    }

    public function test_order_item_insert_omits_price_tiers_column_when_product_has_no_ladder(): void
    {
        $this->setPriceTiersModule(true);
        $user = User::factory()->create();
        $store = $this->pickupStore();
        $product = Product::factory()->for($store)->create(['price' => 279, 'quantity' => 100, 'quantity_step' => 1]);
        $this->putInCart($user, $store, $product, 2, 279);

        $inserts = $this->orderItemInsertSql(function () use ($user, $store): void {
            $this->createPickupOrder($user, $store);
        });

        $this->assertNotEmpty($inserts);
        foreach ($inserts as $sql) {
            $this->assertStringNotContainsString('price_tiers', $sql);
        }
    }

    public function test_order_item_insert_includes_price_tiers_column_when_ladder_applied(): void
    {
        $this->setPriceTiersModule(true);
        $user = User::factory()->create();
        $store = $this->pickupStore();
        $product = Product::factory()->for($store)->create(['price' => 279, 'quantity' => 100, 'quantity_step' => 1]);
        $this->seedPriceTiers($product, $store->id, self::FROM_ONE);
        $this->putInCart($user, $store, $product, 2, 279);

        $inserts = $this->orderItemInsertSql(function () use ($user, $store): void {
            $this->createPickupOrder($user, $store);
        });

        $this->assertNotEmpty($inserts);
        $this->assertTrue(
            collect($inserts)->contains(fn (string $sql) => str_contains($sql, 'price_tiers')),
            'позиция со снапшотом обязана записать price_tiers'
        );
    }
}
