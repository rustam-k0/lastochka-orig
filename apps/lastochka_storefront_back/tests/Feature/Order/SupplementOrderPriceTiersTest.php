<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Actions\Order\SupplementOrderAction;
use App\Enums\OrderStatus;
use App\Enums\ShippingMethod;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Pricing\PriceTierSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TogglesPriceTiersModule;
use Tests\TestCase;

final class SupplementOrderPriceTiersTest extends TestCase
{
    use RefreshDatabase;
    use TogglesPriceTiersModule;

    private const FROM_ONE = [
        ['min_quantity' => 1, 'price' => 279],
        ['min_quantity' => 2, 'price' => 249],
        ['min_quantity' => 5, 'price' => 199],
    ];

    private function order(User $user, Store $store): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'source' => 'storefront',
            'status' => OrderStatus::Assembling,
            'payment_type' => 'cash',
            'is_paid' => false,
            'subtotal' => 747,
            'discount' => 0,
            'eligible_amount' => 0,
            'delivery_cost' => 0,
            'total' => 747,
            'items_count' => 1,
            'shipping_method' => ShippingMethod::Courier,
        ]);
    }

    private function putInCart(User $user, Store $store, Product $product, int $quantity): void
    {
        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => $quantity,
            'price' => $product->price,
            'price_old' => null,
        ]);
    }

    /** Слияние в дозаказе: 3 шт по 249 плюс 2 шт = 5 шт по ступени снапшота позиции. */
    public function test_merge_recalculates_price_by_item_snapshot_and_total_quantity(): void
    {
        $this->setPriceTiersModule(true);
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create(['price' => 279, 'quantity' => 100, 'quantity_step' => 1, 'measurement_unit' => 'piece']);

        // Живая лесенка товара уже другая: пересчёт обязан идти по снапшоту позиции.
        $this->seedPriceTiers($product, $store->id, [['min_quantity' => 5, 'price' => 150]]);

        $order = $this->order($user, $store);
        $existing = OrderItem::factory()->for($order)->for($product)->create([
            'price' => 249, 'price_old' => 279, 'quantity' => 3, 'total' => 747,
            'measurement_unit' => 'piece', 'eligible_for_bonus' => false,
            'price_tiers' => PriceTierSnapshot::build(279.0, null, true, self::FROM_ONE)->toArray(),
        ]);
        $this->putInCart($user, $store, $product, 2);

        app(SupplementOrderAction::class)->execute($order, $user);

        $existing->refresh();
        $this->assertSame(5, (int) $existing->quantity);
        $this->assertSame(199.0, (float) $existing->price, 'ступень снапшота позиции, а не живая лесенка (150)');
        $this->assertSame(279.0, (float) $existing->price_old);
        $this->assertSame(995.0, (float) $existing->total);
        $this->assertFalse($existing->eligible_for_bonus);
        $this->assertSame(995.0, (float) $order->fresh()->subtotal);
    }

    public function test_merge_from_first_tier_turns_item_into_discounted(): void
    {
        $this->setPriceTiersModule(true);
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create(['price' => 279, 'quantity' => 100, 'quantity_step' => 1, 'measurement_unit' => 'piece']);

        $order = $this->order($user, $store);
        $existing = OrderItem::factory()->for($order)->for($product)->create([
            'price' => 279, 'price_old' => null, 'quantity' => 1, 'total' => 279,
            'measurement_unit' => 'piece', 'eligible_for_bonus' => true,
            'price_tiers' => PriceTierSnapshot::build(279.0, null, true, self::FROM_ONE)->toArray(),
        ]);
        $this->putInCart($user, $store, $product, 1);

        app(SupplementOrderAction::class)->execute($order, $user);

        $existing->refresh();
        $this->assertSame(2, (int) $existing->quantity);
        $this->assertSame(249.0, (float) $existing->price);
        $this->assertSame(279.0, (float) $existing->price_old);
        $this->assertFalse($existing->eligible_for_bonus, 'признак пересчитан вместе со ступенью');
    }

    public function test_new_item_gets_fresh_snapshot(): void
    {
        $this->setPriceTiersModule(true);
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $order = $this->order($user, $store);
        OrderItem::factory()->for($order)->create(['price' => 747, 'quantity' => 1, 'total' => 747]);

        $product = Product::factory()->for($store)->create(['price' => 279, 'quantity' => 100, 'quantity_step' => 1, 'measurement_unit' => 'piece']);
        $this->seedPriceTiers($product, $store->id, self::FROM_ONE);
        $this->putInCart($user, $store, $product, 5);

        app(SupplementOrderAction::class)->execute($order, $user);

        $added = $order->items()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(199.0, (float) $added->price);
        $this->assertSame(279.0, (float) $added->price_old);
        $this->assertSame(995.0, (float) $added->total);
        $this->assertFalse($added->eligible_for_bonus);
        $this->assertSame(279.0, (float) $added->price_tiers['base_price']);
        $this->assertCount(3, $added->price_tiers['tiers']);
    }

    public function test_item_without_snapshot_is_merged_as_before(): void
    {
        $this->setPriceTiersModule(true);
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create(['price' => 279, 'quantity' => 100, 'quantity_step' => 1, 'measurement_unit' => 'piece']);
        $this->seedPriceTiers($product, $store->id, self::FROM_ONE);

        $order = $this->order($user, $store);
        $existing = OrderItem::factory()->for($order)->for($product)->create([
            'price' => 260, 'quantity' => 3, 'total' => 780, 'measurement_unit' => 'piece', 'price_tiers' => null,
        ]);
        $this->putInCart($user, $store, $product, 2);

        app(SupplementOrderAction::class)->execute($order, $user);

        $existing->refresh();
        $this->assertSame(5, (int) $existing->quantity);
        $this->assertSame(260.0, (float) $existing->price, 'позиция без снапшота считается по прежней формуле');
        $this->assertSame(1300.0, (float) $existing->total);
        $this->assertNull($existing->price_tiers);
    }

    /** Снапшот у позиции есть, флаг уже выключен: слияние всё равно идёт по снапшоту. */
    public function test_merge_by_snapshot_does_not_depend_on_local_flag(): void
    {
        $this->setPriceTiersModule(false);
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create(['price' => 279, 'quantity' => 100, 'quantity_step' => 1, 'measurement_unit' => 'piece']);

        $order = $this->order($user, $store);
        $existing = OrderItem::factory()->for($order)->for($product)->create([
            'price' => 249, 'price_old' => 279, 'quantity' => 3, 'total' => 747,
            'measurement_unit' => 'piece', 'eligible_for_bonus' => false,
            'price_tiers' => PriceTierSnapshot::build(279.0, null, true, self::FROM_ONE)->toArray(),
        ]);
        $this->putInCart($user, $store, $product, 2);

        app(SupplementOrderAction::class)->execute($order, $user);

        $existing->refresh();
        $this->assertSame(5, (int) $existing->quantity);
        $this->assertSame(199.0, (float) $existing->price);
        $this->assertSame(995.0, (float) $existing->total);
    }

    /**
     * F13(а), финальное ревью: слияние дозаказа по снапшоту на КВАНТОВОМ
     * (граммовом) товаре. Денежный путь с историей багов — код проверен
     * ревью вручную, тест закрепляет: суммарные порции, цену ступени,
     * total, инвариант price × порции = total.
     */
    public function test_merge_by_snapshot_on_quantum_gram_product(): void
    {
        $this->setPriceTiersModule(true);
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create([
            'price' => 279, 'quantity' => 100_000, 'unit_type' => 'kg',
            'quantity_step' => 200, 'measurement_unit' => 'gram',
        ]);

        $order = $this->order($user, $store);
        // 600 г = 3 порции по 200 г; ступень снапшота на 3 порции — 249 (вторая ступень).
        $existing = OrderItem::factory()->for($order)->for($product)->create([
            'price' => 249, 'price_old' => 279, 'quantity' => 600, 'total' => 747,
            'measurement_unit' => 'gram', 'eligible_for_bonus' => false,
            'price_tiers' => PriceTierSnapshot::build(279.0, null, true, self::FROM_ONE)->toArray(),
        ]);
        // Корзина квантового товара хранит граммы: 400 г = 2 порции.
        $this->putInCart($user, $store, $product, 400);

        app(SupplementOrderAction::class)->execute($order, $user);

        $existing->refresh();
        $this->assertSame(1000, (int) $existing->quantity, '600 г + 400 г = 1000 г (5 порций по 200 г)');
        $this->assertSame(199.0, (float) $existing->price, '5 порций — третья ступень снапшота');
        $this->assertSame(279.0, (float) $existing->price_old);
        $this->assertSame(995.0, (float) $existing->total);
        $this->assertFalse($existing->eligible_for_bonus);
        $this->assertSame(
            (float) $existing->total,
            round((float) $existing->price * 5, 2),
            'инвариант: price × порции = total'
        );
    }

    /**
     * F13(а), финальное ревью: слияние дозаказа по снапшоту на ВЕСОВОМ
     * товаре БЕЗ кванта (килограммовый, quantity_step <= 1): порция = 1 кг,
     * корзина хранит килограммы, позиция заказа — граммы.
     */
    public function test_merge_by_snapshot_on_kilogram_product_without_quantum(): void
    {
        $this->setPriceTiersModule(true);
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create([
            // Остаток витрины весового — в граммах (100 кг)
            'price' => 279, 'quantity' => 100000, 'quantity_step' => 1,
            'unit_type' => 'kg', 'measurement_unit' => 'kilogram',
        ]);

        $order = $this->order($user, $store);
        // 2000 г = 2 кг = 2 порции; ступень снапшота на 2 порции — 249.
        $existing = OrderItem::factory()->for($order)->for($product)->create([
            'price' => 249, 'price_old' => 279, 'quantity' => 2000, 'total' => 498,
            'measurement_unit' => 'gram', 'eligible_for_bonus' => false,
            'price_tiers' => PriceTierSnapshot::build(279.0, null, true, self::FROM_ONE)->toArray(),
        ]);
        // Корзина килограммового товара без кванта хранит килограммы: 3 кг = 3 порции.
        $this->putInCart($user, $store, $product, 3);

        app(SupplementOrderAction::class)->execute($order, $user);

        $existing->refresh();
        $this->assertSame(5000, (int) $existing->quantity, '2000 г + 3 кг (×1000) = 5000 г (5 порций по 1 кг)');
        $this->assertSame(199.0, (float) $existing->price, '5 порций — третья ступень снапшота');
        $this->assertSame(279.0, (float) $existing->price_old);
        $this->assertSame(995.0, (float) $existing->total);
        $this->assertFalse($existing->eligible_for_bonus);
        $this->assertSame(
            (float) $existing->total,
            round((float) $existing->price * 5, 2),
            'инвариант: price × порции = total'
        );
    }

    public function test_flag_off_supplement_works_as_before(): void
    {
        $this->setPriceTiersModule(false);
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create(['price' => 279, 'quantity' => 100, 'quantity_step' => 1, 'measurement_unit' => 'piece']);
        $this->seedPriceTiers($product, $store->id, self::FROM_ONE);

        $order = $this->order($user, $store);
        OrderItem::factory()->for($order)->create(['price' => 747, 'quantity' => 1, 'total' => 747]);
        $this->putInCart($user, $store, $product, 5);

        $queries = $this->priceTierTableQueries(fn () => app(SupplementOrderAction::class)->execute($order, $user));

        $added = $order->items()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(0, $queries, 'при выключенном флаге дозаказ не обращается к таблице ступеней');
        $this->assertSame(279.0, (float) $added->price);
        $this->assertSame(1395.0, (float) $added->total);
        $this->assertNull($added->price_tiers, 'новый снапшот строится только при включённом флаге');
    }
}
