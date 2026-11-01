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
use App\Models\PromocodeUsage;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Проверки лимитов использования промокода:
 *  1) Общий max_uses исчерпан → "Промокод больше недоступен".
 *  2) Per-client max_uses_per_client исчерпан → "Вы уже использовали этот промокод".
 *  3) Успешное применение, когда лимиты не исчерпаны.
 */
final class ApplyPromocodeUsageLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_fails_when_total_max_uses_exhausted(): void
    {
        [$user, $store] = $this->seedCart();

        $promocode = Promocode::factory()->percent(10)->create([
            'store_id' => $store->id,
            'scope' => Promocode::SCOPE_STORE,
            'code' => 'EXHAUSTED',
            'max_uses' => 5,
            'used_count' => 5,
        ]);

        // Лимит расходуют использования (без заказа — считаются активными), не used_count.
        foreach (User::factory()->count(5)->create() as $otherUser) {
            PromocodeUsage::create([
                'promocode_id' => $promocode->id,
                'user_id' => $otherUser->id,
                'order_id' => null,
                'used_at' => now()->subDay(),
            ]);
        }

        $this->expectException(InvalidPromocodeException::class);
        $this->expectExceptionMessage('Промокод больше недоступен');

        app(ApplyPromocodeAction::class)->execute($user, $store->id, 'EXHAUSTED');
    }

    public function test_apply_fails_when_per_client_limit_exhausted(): void
    {
        [$user, $store] = $this->seedCart();

        $promocode = Promocode::factory()->percent(10)->create([
            'store_id' => $store->id,
            'scope' => Promocode::SCOPE_STORE,
            'code' => 'FIRSTORDER',
            'max_uses_per_client' => 1,
        ]);

        // Эмулируем прошлое использование этим же клиентом
        PromocodeUsage::create([
            'promocode_id' => $promocode->id,
            'user_id' => $user->id,
            'order_id' => null,
            'used_at' => now()->subDay(),
        ]);

        $this->expectException(InvalidPromocodeException::class);
        $this->expectExceptionMessage('Вы уже использовали этот промокод');

        app(ApplyPromocodeAction::class)->execute($user, $store->id, 'FIRSTORDER');
    }

    public function test_apply_succeeds_when_other_client_used_personal_limit(): void
    {
        [$user, $store] = $this->seedCart();

        $promocode = Promocode::factory()->percent(10)->create([
            'store_id' => $store->id,
            'scope' => Promocode::SCOPE_STORE,
            'code' => 'FIRSTONLY',
            'max_uses_per_client' => 1,
        ]);

        // Другой пользователь уже использовал — для нашего клиента лимит ещё свободен.
        $otherUser = User::factory()->create();
        PromocodeUsage::create([
            'promocode_id' => $promocode->id,
            'user_id' => $otherUser->id,
            'order_id' => null,
            'used_at' => now()->subDay(),
        ]);

        $cart = app(ApplyPromocodeAction::class)->execute($user, $store->id, 'FIRSTONLY');

        $this->assertNotNull($cart);
        $this->assertDatabaseHas('cart_promocodes', [
            'store_id' => $store->id,
            'promocode_id' => $promocode->id,
        ]);
    }

    public function test_apply_succeeds_when_no_limits(): void
    {
        [$user, $store] = $this->seedCart();

        $promocode = Promocode::factory()->percent(10)->create([
            'store_id' => $store->id,
            'scope' => Promocode::SCOPE_STORE,
            'code' => 'NOLIMIT',
            'max_uses' => null,
            'max_uses_per_client' => null,
        ]);

        $cart = app(ApplyPromocodeAction::class)->execute($user, $store->id, 'NOLIMIT');

        $this->assertNotNull($cart);
        $this->assertDatabaseHas('cart_promocodes', [
            'store_id' => $store->id,
            'promocode_id' => $promocode->id,
        ]);
    }

    /**
     * @return array{0: User, 1: Store}
     */
    private function seedCart(): array
    {
        $user = User::factory()->create();

        $store = Store::factory()->create([
            'min_order_amount' => 100,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);

        $category = Category::factory()->forStore($store)->create();

        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 300,
            'quantity' => 10,
            'quantity_step' => 1,
        ]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);

        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 2,
            'price' => 300,
        ]);

        return [$user, $store];
    }
}
