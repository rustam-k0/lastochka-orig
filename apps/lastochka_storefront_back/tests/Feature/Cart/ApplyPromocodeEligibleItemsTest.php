<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Actions\Cart\ApplyPromocodeAction;
use App\Exceptions\InvalidPromocodeException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\Promocode;
use App\Models\Promotion;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Валидация «промокод даст ненулевую скидку» при применении.
 *
 * Скидка считается только по eligible-товарам (без скидки/акции и подходящим
 * под binding промокода). Если eligible-подмножество пустое — промокод
 * раньше «применялся» с нулевой скидкой; теперь должен быть 422:
 *  1) Все товары магазина с ручной скидкой (price_old) → ошибка «участвуют в акции».
 *  2) Все товары магазина в активной акции (promotions) → ошибка «участвуют в акции».
 *  3) Ни один товар не подходит под binding → ошибка «не распространяется».
 *  4) Смешанная корзина (акционный + обычный) → применяется как раньше (регресс).
 *  5) Неактивная/истёкшая акция скидкой не считается → применяется (регресс).
 *  6) Platform-wide: все товары акционные → ошибка (marketplace mode).
 *  7) Platform-wide: binding mismatch → ошибка (marketplace mode).
 *  8) HTTP-контракт: POST /api/v1/cart/promocode → 422 c errors.promocode.
 */
final class ApplyPromocodeEligibleItemsTest extends TestCase
{
    use RefreshDatabase;

    private const MSG_ALL_ON_SALE = 'Промокод нельзя применить: все товары в корзине уже участвуют в акции.';

    private const MSG_BINDING_MISMATCH = 'Промокод не распространяется на товары в корзине.';

    public function test_apply_fails_when_all_store_items_have_manual_discount(): void
    {
        [$user, $store, $category] = $this->seedUserAndStore();

        $product = Product::factory()->for($store)->inCategory($category)->withDiscount()->create([
            'quantity' => 10,
            'quantity_step' => 1,
        ]);
        $this->putInCart($user, $store, $product);

        Promocode::factory()->percent(10)->create([
            'store_id' => $store->id,
            'scope' => Promocode::SCOPE_STORE,
            'code' => 'SALE10',
        ]);

        $this->expectException(InvalidPromocodeException::class);
        $this->expectExceptionMessage(self::MSG_ALL_ON_SALE);

        app(ApplyPromocodeAction::class)->execute($user, $store->id, 'SALE10');
    }

    public function test_apply_fails_when_all_store_items_in_active_promotion(): void
    {
        [$user, $store, $category] = $this->seedUserAndStore();

        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 300,
            'quantity' => 10,
            'quantity_step' => 1,
        ]);
        Promotion::factory()->for($store)->create()->products()->attach($product->id);
        $this->putInCart($user, $store, $product);

        Promocode::factory()->percent(10)->create([
            'store_id' => $store->id,
            'scope' => Promocode::SCOPE_STORE,
            'code' => 'PROMO10',
        ]);

        $this->expectException(InvalidPromocodeException::class);
        $this->expectExceptionMessage(self::MSG_ALL_ON_SALE);

        app(ApplyPromocodeAction::class)->execute($user, $store->id, 'PROMO10');
    }

    public function test_apply_fails_when_no_items_match_binding(): void
    {
        [$user, $store, $category] = $this->seedUserAndStore();

        $productInCart = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 300,
            'quantity' => 10,
            'quantity_step' => 1,
        ]);
        $otherProduct = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 500,
            'quantity' => 10,
            'quantity_step' => 1,
        ]);
        $this->putInCart($user, $store, $productInCart);

        $promocode = Promocode::factory()->percent(10)->create([
            'store_id' => $store->id,
            'scope' => Promocode::SCOPE_STORE,
            'code' => 'BOUND10',
            'binding_type' => 'products',
        ]);
        $promocode->products()->attach($otherProduct->id);

        $this->expectException(InvalidPromocodeException::class);
        $this->expectExceptionMessage(self::MSG_BINDING_MISMATCH);

        app(ApplyPromocodeAction::class)->execute($user, $store->id, 'BOUND10');
    }

    public function test_apply_succeeds_with_mixed_cart(): void
    {
        [$user, $store, $category] = $this->seedUserAndStore();

        $saleProduct = Product::factory()->for($store)->inCategory($category)->withDiscount()->create([
            'quantity' => 10,
            'quantity_step' => 1,
        ]);
        $regularProduct = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 300,
            'quantity' => 10,
            'quantity_step' => 1,
        ]);
        $this->putInCart($user, $store, $saleProduct);
        $this->putInCart($user, $store, $regularProduct);

        $promocode = Promocode::factory()->percent(10)->create([
            'store_id' => $store->id,
            'scope' => Promocode::SCOPE_STORE,
            'code' => 'MIXED10',
        ]);

        $cart = app(ApplyPromocodeAction::class)->execute($user, $store->id, 'MIXED10');

        $this->assertNotNull($cart);
        $this->assertDatabaseHas('cart_promocodes', [
            'store_id' => $store->id,
            'promocode_id' => $promocode->id,
        ]);
    }

    public function test_apply_succeeds_when_promotion_is_inactive_or_expired(): void
    {
        [$user, $store, $category] = $this->seedUserAndStore();

        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 300,
            'quantity' => 10,
            'quantity_step' => 1,
        ]);
        Promotion::factory()->for($store)->inactive()->create()->products()->attach($product->id);
        Promotion::factory()->for($store)->expired()->create()->products()->attach($product->id);
        $this->putInCart($user, $store, $product);

        $promocode = Promocode::factory()->percent(10)->create([
            'store_id' => $store->id,
            'scope' => Promocode::SCOPE_STORE,
            'code' => 'OLDPROMO',
        ]);

        $cart = app(ApplyPromocodeAction::class)->execute($user, $store->id, 'OLDPROMO');

        $this->assertNotNull($cart);
        $this->assertDatabaseHas('cart_promocodes', [
            'store_id' => $store->id,
            'promocode_id' => $promocode->id,
        ]);
    }

    public function test_platform_wide_apply_fails_when_all_items_on_sale(): void
    {
        $this->enableMarketplaceMode();
        [$user, $store, $category] = $this->seedUserAndStore();

        $product = Product::factory()->for($store)->inCategory($category)->withDiscount()->create([
            'quantity' => 10,
            'quantity_step' => 1,
        ]);
        $this->putInCart($user, $store, $product);

        Promocode::factory()->percent(10)->create([
            'store_id' => null,
            'scope' => Promocode::SCOPE_PLATFORM,
            'code' => 'PLATFORM10',
        ]);

        $this->expectException(InvalidPromocodeException::class);
        $this->expectExceptionMessage(self::MSG_ALL_ON_SALE);

        app(ApplyPromocodeAction::class)->execute($user, null, 'PLATFORM10');
    }

    public function test_platform_wide_apply_fails_when_no_items_match_binding(): void
    {
        $this->enableMarketplaceMode();
        [$user, $store, $category] = $this->seedUserAndStore();

        $productInCart = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 300,
            'quantity' => 10,
            'quantity_step' => 1,
        ]);
        $otherProduct = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 500,
            'quantity' => 10,
            'quantity_step' => 1,
        ]);
        $this->putInCart($user, $store, $productInCart);

        $promocode = Promocode::factory()->percent(10)->create([
            'store_id' => null,
            'scope' => Promocode::SCOPE_PLATFORM,
            'code' => 'PLATBOUND',
            'binding_type' => 'products',
        ]);
        $promocode->products()->attach($otherProduct->id);

        $this->expectException(InvalidPromocodeException::class);
        $this->expectExceptionMessage(self::MSG_BINDING_MISMATCH);

        app(ApplyPromocodeAction::class)->execute($user, null, 'PLATBOUND');
    }

    public function test_http_apply_returns_422_with_promocode_error_when_all_items_on_sale(): void
    {
        [$user, $store, $category] = $this->seedUserAndStore();

        $product = Product::factory()->for($store)->inCategory($category)->withDiscount()->create([
            'quantity' => 10,
            'quantity_step' => 1,
        ]);
        $this->putInCart($user, $store, $product);

        Promocode::factory()->percent(10)->create([
            'store_id' => $store->id,
            'scope' => Promocode::SCOPE_STORE,
            'code' => 'SALE10',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/cart/promocode', [
            'storeId' => $store->id,
            'promocode' => 'SALE10',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', self::MSG_ALL_ON_SALE)
            ->assertJsonPath('errors.promocode.0', self::MSG_ALL_ON_SALE);

        $this->assertDatabaseMissing('cart_promocodes', [
            'store_id' => $store->id,
        ]);
    }

    /**
     * @return array{0: User, 1: Store, 2: Category}
     */
    private function seedUserAndStore(): array
    {
        $user = User::factory()->create();

        $store = Store::factory()->create([
            'min_order_amount' => 100,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);

        $category = Category::factory()->forStore($store)->create();

        return [$user, $store, $category];
    }

    private function putInCart(User $user, Store $store, Product $product): void
    {
        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);

        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 2,
            'price' => $product->price,
        ]);
    }

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
}
