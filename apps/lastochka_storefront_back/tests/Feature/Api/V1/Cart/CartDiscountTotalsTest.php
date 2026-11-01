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
 * Товарная скидка в тоталах marketplace-корзины (Platrum 11746):
 * totalAmountBeforeDiscount / totalDiscount учитывают скидку от price_old,
 * а syncPrices при выдаче корзины синхронизирует снапшот cart_items.price_old
 * с актуальным состоянием товара.
 */
final class CartDiscountTotalsTest extends TestCase
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
     * Корзина: товар со скидкой 300 (price_old 400) × 2 + товар без скидки 400 × 1.
     *
     * @return array{0: User, 1: Product, 2: Product, 3: CartItem, 4: CartItem}
     */
    private function seedCartWithDiscountedItem(): array
    {
        $user = User::factory()->create();
        Address::factory()->active()->for($user)->create();

        $store = Store::factory()->create([
            'min_order_amount' => 100,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);
        $category = Category::factory()->forStore($store)->create();

        $discounted = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 300,
            'price_old' => 400,
            'quantity' => 10,
        ]);
        $regular = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 400,
            'quantity' => 10,
        ]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        $discountedItem = CartItem::factory()->for($cart)->for($discounted)->create([
            'store_id' => $store->id,
            'quantity' => 2,
            'price' => 300,
            'price_old' => 400,
        ]);
        $regularItem = CartItem::factory()->for($cart)->for($regular)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 400,
        ]);

        return [$user, $discounted, $regular, $discountedItem, $regularItem];
    }

    /**
     * Найти позицию корзины в ответе по id (dateGroups → items).
     */
    private function findResponseItem(array $json, int $itemId): ?array
    {
        foreach ($json['dateGroups'] as $group) {
            foreach ($group['items'] as $item) {
                if ($item['id'] === $itemId) {
                    return $item;
                }
            }
        }

        return null;
    }

    // ==================== Товарная скидка в тоталах ====================

    public function test_total_amount_before_discount_includes_product_discounts(): void
    {
        $this->enableMarketplaceMode();
        [$user] = $this->seedCartWithDiscountedItem();

        $json = $this->actingAs($user)->getJson('/api/v1/cart')->assertOk()->json();

        // До скидки: 400×2 + 400 = 1200; скидка (400−300)×2 = 200; итог 1000.
        $this->assertEqualsWithDelta(1200.0, (float) $json['totalAmountBeforeDiscount'], 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $json['totalDiscount'], 0.001);
        $this->assertEqualsWithDelta(1000.0, (float) $json['totalAmount'], 0.001);
    }

    public function test_total_discount_combines_product_and_promocode_discounts(): void
    {
        $this->enableMarketplaceMode();
        [$user] = $this->seedCartWithDiscountedItem();

        Promocode::factory()->percent(10)->create([
            'store_id' => null,
            'scope' => Promocode::SCOPE_PLATFORM,
            'code' => 'PLATFORM10',
        ]);
        $this->actingAs($user)
            ->postJson('/api/v1/cart/promocode', ['promocode' => 'PLATFORM10'])
            ->assertOk();

        $json = $this->actingAs($user)->getJson('/api/v1/cart')->assertOk()->json();

        // Промокод применяется только к товару без скидки: 400 × 10% = 40.
        // totalDiscount = товарная 200 + промокод 40 = 240; totalAmount = 1000 − 40 = 960.
        $this->assertEqualsWithDelta(1200.0, (float) $json['totalAmountBeforeDiscount'], 0.001);
        $this->assertEqualsWithDelta(240.0, (float) $json['totalDiscount'], 0.001);
        $this->assertEqualsWithDelta(960.0, (float) $json['totalAmount'], 0.001);
    }

    public function test_totals_equal_without_discounts(): void
    {
        $this->enableMarketplaceMode();
        $user = User::factory()->create();
        Address::factory()->active()->for($user)->create();

        $store = Store::factory()->create([
            'min_order_amount' => 100,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 500,
            'quantity' => 10,
        ]);
        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 500,
        ]);

        $json = $this->actingAs($user)->getJson('/api/v1/cart')->assertOk()->json();

        $this->assertEqualsWithDelta(500.0, (float) $json['totalAmountBeforeDiscount'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $json['totalDiscount'], 0.001);
        $this->assertEqualsWithDelta(500.0, (float) $json['totalAmount'], 0.001);
    }

    // ==================== syncPrices: снапшот price_old ====================

    public function test_sync_prices_backfills_missing_price_old_snapshot(): void
    {
        $this->enableMarketplaceMode();
        // Позиция добавлена ДО назначения скидки товару: снапшот price_old пуст.
        [$user, $discounted, , $discountedItem] = $this->seedCartWithDiscountedItem();
        $discountedItem->update(['price_old' => null]);

        $json = $this->actingAs($user)->getJson('/api/v1/cart')->assertOk()->json();

        $responseItem = $this->findResponseItem($json, $discountedItem->id);
        $this->assertNotNull($responseItem);
        $this->assertEqualsWithDelta(400.0, (float) $responseItem['priceOld'], 0.001);
        $this->assertSame(25, $responseItem['discountPercent']);

        $this->assertEqualsWithDelta(400.0, (float) $discountedItem->fresh()->price_old, 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $json['totalDiscount'], 0.001);
        $this->assertEqualsWithDelta(1200.0, (float) $json['totalAmountBeforeDiscount'], 0.001);
    }

    public function test_sync_prices_clears_stale_price_old_snapshot(): void
    {
        $this->enableMarketplaceMode();
        // Скидку с товара сняли, но в снапшоте позиции осталась старая цена.
        [$user, $discounted, , $discountedItem] = $this->seedCartWithDiscountedItem();
        $discounted->update(['price_old' => null]);

        $json = $this->actingAs($user)->getJson('/api/v1/cart')->assertOk()->json();

        $responseItem = $this->findResponseItem($json, $discountedItem->id);
        $this->assertNotNull($responseItem);
        $this->assertNull($responseItem['priceOld']);
        $this->assertNull($responseItem['discountPercent']);

        $this->assertNull($discountedItem->fresh()->price_old);
        // Скидки больше нет: тоталы совпадают (1000 = 300×2 + 400).
        $this->assertEqualsWithDelta(0.0, (float) $json['totalDiscount'], 0.001);
        $this->assertEqualsWithDelta(1000.0, (float) $json['totalAmountBeforeDiscount'], 0.001);
        $this->assertEqualsWithDelta(1000.0, (float) $json['totalAmount'], 0.001);
    }
}
