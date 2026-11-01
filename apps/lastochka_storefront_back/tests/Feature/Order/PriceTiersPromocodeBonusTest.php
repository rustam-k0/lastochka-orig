<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Actions\Cart\ApplyPromocodeAction;
use App\Actions\Order\CreateOrderAction;
use App\DTOs\Order\CreateOrderData;
use App\Enums\ShippingMethod;
use App\Exceptions\InvalidPromocodeException;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promocode;
use App\Models\Promotion;
use App\Models\Store;
use App\Models\User;
use App\Services\Pickup\PickupSlotService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TogglesPriceTiersModule;
use Tests\TestCase;

/**
 * Промокод и бонусы на первой и на дешёвой ступени (правило 3 спеки): пока
 * сработала первая ступень, позиция обычная; ступень дешевле опорной цены
 * делает позицию скидочной. Корзина и заказ обязаны считать одинаково.
 */
final class PriceTiersPromocodeBonusTest extends TestCase
{
    use RefreshDatabase;
    use TogglesPriceTiersModule;

    private User $user;

    private Store $store;

    private Product $tiered;

    private Product $plain;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-02 08:00:00', config('app.timezone')));

        $this->setPriceTiersModule(true);

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create([
            'is_active' => true,
            'is_pickup_enabled' => true,
            'is_open_24_7' => true,
            'slot_duration' => 60,
            'min_pickup_order_amount' => 0,
            'min_order_amount' => 0,
            'pickup_payment_methods' => ['cash', 'card'],
            'is_bonus_enabled' => true,
            'bonus_earn_percent' => 10,
            'bonus_spend_percent' => 0,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);
        $category = Category::factory()->forStore($this->store)->create();

        $this->tiered = Product::factory()->for($this->store)->inCategory($category)->create([
            'price' => 279, 'quantity' => 100, 'quantity_step' => 1, 'measurement_unit' => 'piece',
        ]);
        $this->plain = Product::factory()->for($this->store)->inCategory($category)->create([
            'price' => 100, 'quantity' => 100, 'quantity_step' => 1, 'measurement_unit' => 'piece',
        ]);

        $this->seedPriceTiers($this->tiered, $this->store->id, [
            ['min_quantity' => 1, 'price' => 279],
            ['min_quantity' => 2, 'price' => 249],
            ['min_quantity' => 5, 'price' => 199],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function addToCart(Product $product, int $quantity): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/cart/items', [
            'productId' => $product->id,
            'quantity' => $quantity,
        ])->assertOk();
    }

    private function promocode(string $code): void
    {
        Promocode::factory()->percent(10)->create([
            'store_id' => $this->store->id,
            'scope' => Promocode::SCOPE_STORE,
            'code' => $code,
        ]);
    }

    /** @return array<string, mixed> группа магазина из GET /cart */
    private function cartGroup(): array
    {
        return $this->actingAs($this->user)->getJson('/api/v1/cart?shippingMethod=pickup')->assertOk()->json('storeGroups.0');
    }

    private function createOrder(?string $bonusAction = null): Order
    {
        $slots = app(PickupSlotService::class)->getSlots($this->store, now()->format('Y-m-d'), null);

        $result = app(CreateOrderAction::class)->execute(new CreateOrderData(
            user: $this->user->fresh(),
            storeId: $this->store->id,
            address: null,
            deliverySlotId: $slots[0]['id'],
            shippingMethod: ShippingMethod::Pickup,
            bonusAction: $bonusAction,
            paymentType: 'cash',
        ));

        return Order::with('items')->findOrFail($result['orderId']);
    }

    // ==================== Промокод ====================

    public function test_promocode_applies_to_first_tier_item_in_cart_and_order(): void
    {
        $this->addToCart($this->tiered, 1);
        $this->addToCart($this->plain, 1);
        $this->promocode('FIRST10');
        app(ApplyPromocodeAction::class)->execute($this->user, $this->store->id, 'FIRST10');

        $this->assertEquals(37.9, (float) $this->cartGroup()['discount'], '10% от 279 + 100');

        $order = $this->createOrder();
        $this->assertSame(37.9, (float) $order->discount);
        $this->assertSame(379.0, (float) $order->subtotal);
    }

    public function test_promocode_skips_cheaper_tier_item_in_cart_and_order(): void
    {
        $this->addToCart($this->tiered, 5);
        $this->addToCart($this->plain, 1);
        $this->promocode('CHEAP10');
        app(ApplyPromocodeAction::class)->execute($this->user, $this->store->id, 'CHEAP10');

        $this->assertEquals(10.0, (float) $this->cartGroup()['discount'], '10% только от обычного товара');

        $order = $this->createOrder();
        $this->assertSame(10.0, (float) $order->discount);
        $this->assertSame(1095.0, (float) $order->subtotal, '5 × 199 + 100');
    }

    public function test_promocode_cannot_be_applied_when_only_item_is_on_cheaper_tier(): void
    {
        $this->addToCart($this->tiered, 5);
        $this->promocode('ONLY10');

        $this->expectException(InvalidPromocodeException::class);

        app(ApplyPromocodeAction::class)->execute($this->user, $this->store->id, 'ONLY10');
    }

    /** Акция на товаре к позиции первой ступени не применилась: промокод действует. */
    public function test_promotion_on_product_does_not_block_promocode_on_first_tier(): void
    {
        Promotion::factory()->for($this->store)->create(['discount_percent' => 15])
            ->products()->attach($this->tiered->id);

        $this->addToCart($this->tiered, 1);
        $this->promocode('PROMO10');
        app(ApplyPromocodeAction::class)->execute($this->user, $this->store->id, 'PROMO10');

        $group = $this->cartGroup();
        $this->assertEquals(279.0, (float) $group['items'][0]['price'], 'ступень приоритетнее акции');
        $this->assertEquals(27.9, (float) $group['discount']);
    }

    // ==================== Бонусы ====================

    public function test_bonuses_are_earned_on_first_tier_item(): void
    {
        $this->addToCart($this->tiered, 1);
        $this->addToCart($this->plain, 1);

        $this->assertSame(379, $this->cartGroup()['bonus']['eligibleAmount']);

        $order = $this->createOrder('earn');
        $this->assertSame(379, (int) $order->eligible_amount);
        $this->assertSame(37, (int) $order->bonus_earned);
        $this->assertTrue($order->items->firstWhere('product_id', $this->tiered->id)->eligible_for_bonus);
    }

    public function test_bonuses_are_not_earned_on_cheaper_tier_item(): void
    {
        $this->addToCart($this->tiered, 5);
        $this->addToCart($this->plain, 1);

        $this->assertSame(100, $this->cartGroup()['bonus']['eligibleAmount']);

        $order = $this->createOrder('earn');
        $this->assertSame(100, (int) $order->eligible_amount);
        $this->assertSame(10, (int) $order->bonus_earned);
        $this->assertFalse($order->items->firstWhere('product_id', $this->tiered->id)->eligible_for_bonus);
    }
}
