<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Order;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promocode;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Приём бонусов при создании marketplace client-order (Platrum 11784):
 * bonusAction/bonusSpendAmount в POST /v1/client-orders, greedy-распределение
 * spend по sub-order'ам, несовместимость с платформенным промокодом.
 */
final class ClientOrderBonusCreateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    // ==================== Helpers ====================

    private function enableMarketplaceMode(): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    'marketplace_mode' => ['type' => 'select', 'value' => 'marketplace'],
                ], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ],
        );
        app(ModuleConfigService::class)->invalidateCache();
    }

    /**
     * Корзина из двух бонусных магазинов:
     * store1 — товар 2×300=600, spend 50% → квота 300;
     * store2 — товар 1×400=400, spend 50% → квота 200.
     *
     * @return array{0: User, 1: Address, 2: Store, 3: Store}
     */
    private function seedBonusCart(
        int $spendPercent1 = 50,
        int $spendPercent2 = 50,
        bool $bonusEnabled2 = true,
    ): array {
        $user = User::factory()->create(['bonus_balance' => 1000, 'bonus_reserved' => 0]);
        $address = Address::factory()->active()->for($user)->create();

        $store1 = Store::factory()->create([
            'min_order_amount' => null,
            'delivery_price' => 0,
            'free_delivery_from' => null,
            'is_bonus_enabled' => true,
            'bonus_earn_percent' => 5,
            'bonus_spend_percent' => $spendPercent1,
        ]);
        $store2 = Store::factory()->create([
            'min_order_amount' => null,
            'delivery_price' => 0,
            'free_delivery_from' => null,
            'is_bonus_enabled' => $bonusEnabled2,
            'bonus_earn_percent' => 5,
            'bonus_spend_percent' => $spendPercent2,
        ]);

        $cat1 = Category::factory()->forStore($store1)->create();
        $cat2 = Category::factory()->forStore($store2)->create();
        $product1 = Product::factory()->for($store1)->inCategory($cat1)->create(['price' => 300, 'quantity' => 10]);
        $product2 = Product::factory()->for($store2)->inCategory($cat2)->create(['price' => 400, 'quantity' => 10]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product1)->create([
            'store_id' => $store1->id, 'quantity' => 2, 'price' => 300,
        ]);
        CartItem::factory()->for($cart)->for($product2)->create([
            'store_id' => $store2->id, 'quantity' => 1, 'price' => 400,
        ]);

        return [$user, $address, $store1, $store2];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function postClientOrder(User $user, Address $address, Store $store1, Store $store2, array $extra = []): TestResponse
    {
        $slotId = $this->getValidDeliverySlotId();

        return $this->actingAs($user)->postJson('/api/v1/client-orders', array_merge([
            'addressId' => $address->id,
            'paymentType' => 'cash',
            'deliverySlots' => [
                ['storeId' => $store1->id, 'slotId' => $slotId],
                ['storeId' => $store2->id, 'slotId' => $slotId],
            ],
        ], $extra));
    }

    // ==================== spend ====================

    public function test_spend_distributed_greedy_across_sub_orders(): void
    {
        $this->enableMarketplaceMode();
        [$user, $address, $store1, $store2] = $this->seedBonusCart();

        // Квоты: store1=300, store2=200. Запрос 400 → store1: 300, store2: 100.
        $response = $this->postClientOrder($user, $address, $store1, $store2, [
            'bonusAction' => 'spend',
            'bonusSpendAmount' => 400,
        ]);

        $response->assertOk();

        $order1 = Order::where('store_id', $store1->id)->firstOrFail();
        $order2 = Order::where('store_id', $store2->id)->firstOrFail();
        $this->assertSame(300, (int) $order1->bonus_spent);
        $this->assertSame(100, (int) $order2->bonus_spent);

        // Резерв на балансе: 400; total sub-order'ов уменьшены на бонусы
        // (FakeDeliveryClient добавляет deliveryPrice=200 каждому заказу).
        $this->assertSame(400, (int) $user->fresh()->bonus_reserved);
        $this->assertEqualsWithDelta(500.0, (float) $order1->total, 0.001); // 600 − 300 + 200
        $this->assertEqualsWithDelta(500.0, (float) $order2->total, 0.001); // 400 − 100 + 200
    }

    public function test_spend_skips_store_without_bonus_program(): void
    {
        $this->enableMarketplaceMode();
        [$user, $address, $store1, $store2] = $this->seedBonusCart(bonusEnabled2: false);

        // Квота только store1 = 300. Запрос 400 → спишется 300, store2 нетронут.
        $this->postClientOrder($user, $address, $store1, $store2, [
            'bonusAction' => 'spend',
            'bonusSpendAmount' => 400,
        ])->assertOk();

        $this->assertSame(300, (int) Order::where('store_id', $store1->id)->firstOrFail()->bonus_spent);
        $this->assertSame(0, (int) Order::where('store_id', $store2->id)->firstOrFail()->bonus_spent);
        $this->assertSame(300, (int) $user->fresh()->bonus_reserved);
    }

    public function test_spend_fails_when_no_quota(): void
    {
        $this->enableMarketplaceMode();
        // spend_percent = 0 у обоих → квоты нулевые → 422
        [$user, $address, $store1, $store2] = $this->seedBonusCart(spendPercent1: 0, spendPercent2: 0);

        $response = $this->postClientOrder($user, $address, $store1, $store2, [
            'bonusAction' => 'spend',
            'bonusSpendAmount' => 100,
        ]);

        $response->assertStatus(422)->assertJsonPath('error', 'bonus_spend_failed');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_spend_requires_amount(): void
    {
        $this->enableMarketplaceMode();
        [$user, $address, $store1, $store2] = $this->seedBonusCart();

        $this->postClientOrder($user, $address, $store1, $store2, [
            'bonusAction' => 'spend',
        ])->assertStatus(422)->assertJsonValidationErrors(['bonusSpendAmount']);
    }

    public function test_spend_amount_cannot_exceed_available_balance(): void
    {
        $this->enableMarketplaceMode();
        [$user, $address, $store1, $store2] = $this->seedBonusCart();
        $user->forceFill(['bonus_balance' => 50, 'bonus_reserved' => 0])->save();

        $this->postClientOrder($user, $address, $store1, $store2, [
            'bonusAction' => 'spend',
            'bonusSpendAmount' => 100,
        ])->assertStatus(422)->assertJsonValidationErrors(['bonusSpendAmount']);
    }

    // ==================== earn ====================

    public function test_earn_creates_earn_snapshots_for_bonus_stores(): void
    {
        $this->enableMarketplaceMode();
        [$user, $address, $store1, $store2] = $this->seedBonusCart();

        $this->postClientOrder($user, $address, $store1, $store2, [
            'bonusAction' => 'earn',
        ])->assertOk();

        $order1 = Order::where('store_id', $store1->id)->firstOrFail();
        $order2 = Order::where('store_id', $store2->id)->firstOrFail();

        // earn 5%: 600 → 30, 400 → 20. Начисление произойдёт при delivered (M.14),
        // на создании — снапшот и зеркальные поля.
        $this->assertSame(30, (int) $order1->bonus_earned);
        $this->assertSame(20, (int) $order2->bonus_earned);
        $this->assertSame('earn', $order1->bonus->type);
        $this->assertSame('earn', $order2->bonus->type);
        $this->assertSame(0, (int) $user->fresh()->bonus_reserved);
    }

    // ==================== несовместимость с промокодом ====================

    public function test_bonus_rejected_with_platform_promocode(): void
    {
        $this->enableMarketplaceMode();
        [$user, $address, $store1, $store2] = $this->seedBonusCart();

        Promocode::factory()->percent(10)->create([
            'store_id' => null,
            'scope' => Promocode::SCOPE_PLATFORM,
            'code' => 'PLATFORM10',
        ]);
        $this->actingAs($user)
            ->postJson('/api/v1/cart/promocode', ['promocode' => 'PLATFORM10'])
            ->assertOk();

        $response = $this->postClientOrder($user, $address, $store1, $store2, [
            'bonusAction' => 'spend',
            'bonusSpendAmount' => 100,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['bonusAction']);
        $this->assertDatabaseCount('orders', 0);
    }

    // ==================== отмена до сборки ====================

    public function test_cancel_before_assembly_releases_holds_without_refund(): void
    {
        $this->enableMarketplaceMode();
        [$user, $address, $store1, $store2] = $this->seedBonusCart();

        $clientOrderId = $this->postClientOrder($user, $address, $store1, $store2, [
            'bonusAction' => 'spend',
            'bonusSpendAmount' => 400,
        ])->assertOk()->json('clientOrderId');

        // Предусловие: резерв взят, фактического списания нет.
        $this->assertSame(400, (int) $user->fresh()->bonus_reserved);
        $this->assertSame(1000, (int) $user->fresh()->bonus_balance);

        $this->actingAs($user)
            ->deleteJson('/api/v1/client-orders/'.$clientOrderId)
            ->assertOk();

        $fresh = $user->fresh();
        // Резерв освобождён, баланс НЕ изменился: до сборки списания не было,
        // значит и возвращать нечего — фантомный refund запрещён.
        $this->assertSame(0, (int) $fresh->bonus_reserved);
        $this->assertSame(1000, (int) $fresh->bonus_balance);
        $this->assertSame(0, \App\Models\BonusTransaction::where('user_id', $user->id)->where('type', 'refund')->count());
        $this->assertSame(0, \App\Models\BonusHold::where('user_id', $user->id)->where('status', \App\Models\BonusHold::STATUS_HELD)->count());
    }

    public function test_cancel_before_assembly_is_idempotent_for_holds(): void
    {
        $this->enableMarketplaceMode();
        [$user, $address, $store1, $store2] = $this->seedBonusCart();

        $clientOrderId = $this->postClientOrder($user, $address, $store1, $store2, [
            'bonusAction' => 'spend',
            'bonusSpendAmount' => 400,
        ])->assertOk()->json('clientOrderId');

        $this->actingAs($user)->deleteJson('/api/v1/client-orders/'.$clientOrderId)->assertOk();
        $this->actingAs($user)->deleteJson('/api/v1/client-orders/'.$clientOrderId)->assertOk();

        $fresh = $user->fresh();
        $this->assertSame(0, (int) $fresh->bonus_reserved);
        $this->assertSame(1000, (int) $fresh->bonus_balance);
    }

    // ==================== регрессия ====================

    public function test_order_without_bonus_action_creates_no_bonus_snapshots(): void
    {
        $this->enableMarketplaceMode();
        [$user, $address, $store1, $store2] = $this->seedBonusCart();

        $this->postClientOrder($user, $address, $store1, $store2)->assertOk();

        $this->assertDatabaseCount('order_bonuses', 0);
        $this->assertSame(0, (int) $user->fresh()->bonus_reserved);
    }
}
