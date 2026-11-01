<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\ReadyTime\ReadyTimeResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TogglesProductReadyTimeModule;
use Tests\TestCase;

final class CartReadyTimeTest extends TestCase
{
    use RefreshDatabase;
    use TogglesProductReadyTimeModule;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->invalidateProductReadyTimeCache();
        parent::tearDown();
    }

    /**
     * @return array{User, Store, Cart}
     */
    private function makeCartWith(array $productsReady): array
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $cart = Cart::factory()->create(['user_id' => $user->id]);

        foreach ($productsReady as $ready) {
            $product = Product::factory()->create(array_merge(
                ['store_id' => $store->id, 'quantity' => 100],
                $ready,
            ));

            CartItem::factory()->create([
                'cart_id' => $cart->id,
                'store_id' => $store->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => 100,
            ]);
        }

        return [$user, $store, $cart];
    }

    public function test_resolver_takes_max_ready_at(): void
    {
        $this->setProductReadyTimeModule(true);
        Carbon::setTestNow(Carbon::parse('2026-07-02 09:00:00', config('app.timezone')));

        [$user, $store] = $this->makeCartWith([
            ['ready_fixed_time' => '12:00:00'],
            ['ready_fixed_time' => '14:00:00'], // позже
            ['ready_fixed_time' => null],
        ]);

        $max = app(ReadyTimeResolver::class)->forUserStore($user, $store->id);

        $this->assertSame(
            Carbon::parse('2026-07-02 14:00:00', config('app.timezone'))->toIso8601String(),
            $max->toIso8601String(),
        );
    }

    public function test_resolver_returns_null_when_module_disabled(): void
    {
        $this->setProductReadyTimeModule(false);

        [$user, $store] = $this->makeCartWith([
            ['ready_fixed_time' => '23:59:00'],
        ]);

        $this->assertNull(app(ReadyTimeResolver::class)->forUserStore($user, $store->id));
    }

    public function test_cart_endpoint_exposes_group_available_from(): void
    {
        $this->setProductReadyTimeModule(true);
        Carbon::setTestNow(Carbon::parse('2026-07-02 09:00:00', config('app.timezone')));

        [$user] = $this->makeCartWith([
            ['ready_fixed_time' => '12:00:00'],
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath(
                'storeGroups.0.availableFrom',
                Carbon::parse('2026-07-02 12:00:00', config('app.timezone'))->toIso8601String(),
            );
    }

    /**
     * Дочерняя позиция (добавка из каталога, parent_id != null) с готовностью
     * позже всех корневых позиций — обе точки расчёта (forUserStore и
     * агрегат в GET /api/v1/cart) обязаны учесть именно её время, иначе
     * слот показывается доступным, а создание заказа падает по валидации.
     */
    public function test_child_item_ready_time_is_taken_into_account(): void
    {
        $this->setProductReadyTimeModule(true);
        Carbon::setTestNow(Carbon::parse('2026-07-02 09:00:00', config('app.timezone')));

        $user = User::factory()->create();
        $store = Store::factory()->create();
        $cart = Cart::factory()->create(['user_id' => $user->id]);

        $mainProduct = Product::factory()->create([
            'store_id' => $store->id,
            'quantity' => 100,
            'ready_fixed_time' => '12:00:00',
        ]);
        $supplementProduct = Product::factory()->create([
            'store_id' => $store->id,
            'quantity' => 100,
            'ready_fixed_time' => '14:00:00', // позже корневой позиции
        ]);

        $root = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'store_id' => $store->id,
            'product_id' => $mainProduct->id,
            'quantity' => 1,
            'price' => 100,
        ]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'store_id' => $store->id,
            'product_id' => $supplementProduct->id,
            'quantity' => 1,
            'price' => 50,
            'parent_id' => $root->id,
        ]);

        $expected = Carbon::parse('2026-07-02 14:00:00', config('app.timezone'));

        $max = app(ReadyTimeResolver::class)->forUserStore($user, $store->id);
        $this->assertSame($expected->toIso8601String(), $max->toIso8601String());

        $this->actingAs($user)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('storeGroups.0.availableFrom', $expected->toIso8601String());
    }
}
