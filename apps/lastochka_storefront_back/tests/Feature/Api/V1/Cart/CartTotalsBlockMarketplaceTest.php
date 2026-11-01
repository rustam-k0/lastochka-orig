<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Cart;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\Promocode;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Блок «Итого» экрана оформления заказа (Platrum 11784):
 * totalItemsCount / promotionDiscount / vatAmount / totalToPay /
 * shippingMethod-обработка / агрегированный bonus-блок.
 */
final class CartTotalsBlockMarketplaceTest extends TestCase
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
     * Корзина из двух магазинов: 2×300 (store1) + 1×400 (store2) = 1000.
     *
     * @return array{0: User, 1: Store, 2: Store, 3: Product, 4: Product}
     */
    private function seedCart(
        array $store1Attrs = [],
        array $store2Attrs = [],
        array $product1Attrs = [],
        array $product2Attrs = [],
    ): array {
        $user = User::factory()->create();
        Address::factory()->active()->for($user)->create();

        $store1 = Store::factory()->create(array_merge([
            'min_order_amount' => 100,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ], $store1Attrs));
        $store2 = Store::factory()->create(array_merge([
            'min_order_amount' => 100,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ], $store2Attrs));

        $cat1 = Category::factory()->forStore($store1)->create();
        $cat2 = Category::factory()->forStore($store2)->create();

        $product1 = Product::factory()->for($store1)->inCategory($cat1)->create(array_merge([
            'price' => 300,
            'quantity' => 10,
        ], $product1Attrs));
        $product2 = Product::factory()->for($store2)->inCategory($cat2)->create(array_merge([
            'price' => 400,
            'quantity' => 10,
        ], $product2Attrs));

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product1)->create([
            'store_id' => $store1->id,
            'quantity' => 2,
            'price' => 300,
        ]);
        CartItem::factory()->for($cart)->for($product2)->create([
            'store_id' => $store2->id,
            'quantity' => 1,
            'price' => 400,
        ]);

        return [$user, $store1, $store2, $product1, $product2];
    }

    // ==================== totalItemsCount / totalToPay ====================

    public function test_totals_block_fields_present(): void
    {
        $this->enableMarketplaceMode();
        [$user] = $this->seedCart();

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk()->assertJsonStructure([
            'totalItemsCount',
            'promotionDiscount',
            'vatAmount',
            'totalToPay',
        ]);
        $this->assertSame(2, $response->json('totalItemsCount'));
    }

    public function test_total_to_pay_equals_total_amount_plus_shipping(): void
    {
        $this->enableMarketplaceMode();
        [$user] = $this->seedCart();

        $json = $this->actingAs($user)->getJson('/api/v1/cart')->assertOk()->json();

        $this->assertEqualsWithDelta(
            (float) $json['totalAmount'] + (float) $json['totalShippingPrice'],
            (float) $json['totalToPay'],
            0.001,
        );
    }

    public function test_empty_cart_returns_zeroed_totals_block(): void
    {
        $this->enableMarketplaceMode();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk()
            ->assertJsonPath('totalItemsCount', 0)
            ->assertJsonPath('promotionDiscount', 0)
            ->assertJsonPath('vatAmount', 0)
            ->assertJsonPath('totalToPay', 0)
            ->assertJsonPath('bonus', null);
    }

    // ==================== promotionDiscount ====================

    public function test_promotion_discount_aggregates_price_old_savings(): void
    {
        $this->enableMarketplaceMode();
        // store1: товар 2 шт по 300 с price_old 400 → экономия (400−300)×2 = 200
        [$user, $store1, $store2, $product1] = $this->seedCart(product1Attrs: [
            'price' => 300,
            'price_old' => 400,
        ]);
        // Синхронизировать price_old в cart_item (snapshot скидки)
        CartItem::query()->where('product_id', $product1->id)->update(['price_old' => 400]);

        $json = $this->actingAs($user)->getJson('/api/v1/cart')->assertOk()->json();

        $this->assertEqualsWithDelta(200.0, (float) $json['promotionDiscount'], 0.001);
    }

    public function test_promotion_discount_zero_without_sales(): void
    {
        $this->enableMarketplaceMode();
        [$user] = $this->seedCart();

        $json = $this->actingAs($user)->getJson('/api/v1/cart')->assertOk()->json();

        $this->assertEqualsWithDelta(0.0, (float) $json['promotionDiscount'], 0.001);
    }

    // ==================== vatAmount ====================

    public function test_vat_amount_uses_default_code_when_product_has_no_vat_rate(): void
    {
        $this->enableMarketplaceMode();
        // vat_rate = null у обоих товаров → дефолтный код 4 (20%).
        [$user] = $this->seedCart(product1Attrs: ['vat_rate' => null], product2Attrs: ['vat_rate' => null]);

        $json = $this->actingAs($user)->getJson('/api/v1/cart')->assertOk()->json();

        // Позиция 1: 600 × 20/120 = 100.00; позиция 2: 400 × 20/120 = 66.67
        $this->assertEqualsWithDelta(166.67, (float) $json['vatAmount'], 0.001);
    }

    public function test_vat_amount_respects_product_vat_code(): void
    {
        $this->enableMarketplaceMode();
        // product1 код 3 (10%): 600 × 10/110 = 54.55; product2 код 1 (без НДС): 0
        [$user] = $this->seedCart(
            product1Attrs: ['vat_rate' => 3],
            product2Attrs: ['vat_rate' => 1],
        );

        $json = $this->actingAs($user)->getJson('/api/v1/cart')->assertOk()->json();

        $this->assertEqualsWithDelta(54.55, (float) $json['vatAmount'], 0.001);
    }

    // ==================== shippingMethod ====================

    public function test_pickup_shipping_method_zeroes_delivery(): void
    {
        $this->enableMarketplaceMode();
        [$user] = $this->seedCart(
            store1Attrs: ['delivery_price' => 150],
            store2Attrs: ['delivery_price' => 250],
        );

        // Контроль предусловия: при courier доставка платная.
        $courier = $this->actingAs($user)->getJson('/api/v1/cart')->assertOk()->json();
        $this->assertGreaterThan(0, (float) $courier['totalShippingPrice']);

        $json = $this->actingAs($user)->getJson('/api/v1/cart?shippingMethod=pickup')->assertOk()->json();

        $this->assertEqualsWithDelta(0.0, (float) $json['totalShippingPrice'], 0.001);
        $this->assertEqualsWithDelta((float) $json['totalAmount'], (float) $json['totalToPay'], 0.001);
    }

    public function test_cdek_and_russian_post_zero_delivery_in_cart(): void
    {
        $this->enableMarketplaceMode();
        [$user] = $this->seedCart(store1Attrs: ['delivery_price' => 150]);

        foreach (['cdek', 'russian_post'] as $method) {
            $json = $this->actingAs($user)->getJson('/api/v1/cart?shippingMethod='.$method)->assertOk()->json();
            $this->assertEqualsWithDelta(0.0, (float) $json['totalShippingPrice'], 0.001, "method={$method}");
        }
    }

    public function test_courier_method_keeps_existing_shipping_logic(): void
    {
        $this->enableMarketplaceMode();
        [$user] = $this->seedCart(store1Attrs: ['delivery_price' => 150]);

        // Без параметра и с courier ответ идентичен (регрессия текущего поведения).
        $default = $this->actingAs($user)->getJson('/api/v1/cart')->assertOk()->json();
        $courier = $this->actingAs($user)->getJson('/api/v1/cart?shippingMethod=courier')->assertOk()->json();

        $this->assertSame($default['totalShippingPrice'], $courier['totalShippingPrice']);
        $this->assertSame($default['totalToPay'], $courier['totalToPay']);
    }

    // ==================== bonus block ====================

    public function test_bonus_block_null_when_no_bonus_stores(): void
    {
        $this->enableMarketplaceMode();
        [$user] = $this->seedCart(); // is_bonus_enabled по умолчанию false

        $json = $this->actingAs($user)->getJson('/api/v1/cart')->assertOk()->json();

        $this->assertNull($json['bonus']);
    }

    public function test_bonus_block_aggregates_across_stores(): void
    {
        $this->enableMarketplaceMode();
        // store1: 600 eligible, earn 5% → 30, spend 10% → 60
        // store2: 400 eligible, earn 10% → 40, spend 50% → 200
        [$user] = $this->seedCart(
            store1Attrs: ['is_bonus_enabled' => true, 'bonus_earn_percent' => 5, 'bonus_spend_percent' => 10],
            store2Attrs: ['is_bonus_enabled' => true, 'bonus_earn_percent' => 10, 'bonus_spend_percent' => 50],
        );
        $user->forceFill(['bonus_balance' => 1000, 'bonus_reserved' => 0])->save();

        $json = $this->actingAs($user)->getJson('/api/v1/cart')->assertOk()->json();

        $this->assertTrue($json['bonus']['isEnabled']);
        $this->assertSame(1000, $json['bonus']['eligibleAmount']);
        $this->assertSame(70, $json['bonus']['maxEarn']);   // 30 + 40
        $this->assertSame(260, $json['bonus']['maxSpend']); // 60 + 200 ≤ 1000
        $this->assertSame(1000, $json['bonus']['userBalance']);
    }

    public function test_bonus_max_spend_capped_by_available_balance(): void
    {
        $this->enableMarketplaceMode();
        [$user] = $this->seedCart(
            store1Attrs: ['is_bonus_enabled' => true, 'bonus_earn_percent' => 5, 'bonus_spend_percent' => 50],
            store2Attrs: ['is_bonus_enabled' => true, 'bonus_earn_percent' => 5, 'bonus_spend_percent' => 50],
        );
        // available = 1000 − 950 = 50 < сумма квот (300 + 200)
        $user->forceFill(['bonus_balance' => 1000, 'bonus_reserved' => 950])->save();

        $json = $this->actingAs($user)->getJson('/api/v1/cart')->assertOk()->json();

        $this->assertSame(50, $json['bonus']['maxSpend']);
        $this->assertSame(50, $json['bonus']['userBalance']);
    }

    public function test_bonus_eligible_excludes_discounted_products(): void
    {
        $this->enableMarketplaceMode();
        // product1 со скидкой (price_old > price) → исключён из eligible
        [$user] = $this->seedCart(
            store1Attrs: ['is_bonus_enabled' => true, 'bonus_earn_percent' => 10, 'bonus_spend_percent' => 10],
            store2Attrs: ['is_bonus_enabled' => true, 'bonus_earn_percent' => 10, 'bonus_spend_percent' => 10],
            product1Attrs: ['price' => 300, 'price_old' => 400],
        );
        $user->forceFill(['bonus_balance' => 1000, 'bonus_reserved' => 0])->save();

        $json = $this->actingAs($user)->getJson('/api/v1/cart')->assertOk()->json();

        // eligible только store2: 400
        $this->assertSame(400, $json['bonus']['eligibleAmount']);
        $this->assertSame(40, $json['bonus']['maxEarn']);
        $this->assertSame(40, $json['bonus']['maxSpend']);
    }

    public function test_bonus_zeroed_when_platform_promocode_applied(): void
    {
        $this->enableMarketplaceMode();
        [$user] = $this->seedCart(
            store1Attrs: ['is_bonus_enabled' => true, 'bonus_earn_percent' => 10, 'bonus_spend_percent' => 10],
            store2Attrs: ['is_bonus_enabled' => true, 'bonus_earn_percent' => 10, 'bonus_spend_percent' => 10],
        );
        $user->forceFill(['bonus_balance' => 1000, 'bonus_reserved' => 0])->save();

        Promocode::factory()->percent(10)->create([
            'store_id' => null,
            'scope' => Promocode::SCOPE_PLATFORM,
            'code' => 'PLATFORM10',
        ]);
        $this->actingAs($user)
            ->postJson('/api/v1/cart/promocode', ['promocode' => 'PLATFORM10'])
            ->assertOk();

        $json = $this->actingAs($user)->getJson('/api/v1/cart')->assertOk()->json();

        // Блок присутствует (программа включена), но квоты обнулены промокодом.
        $this->assertTrue($json['bonus']['isEnabled']);
        $this->assertSame(0, $json['bonus']['eligibleAmount']);
        $this->assertSame(0, $json['bonus']['maxEarn']);
        $this->assertSame(0, $json['bonus']['maxSpend']);
    }
}
