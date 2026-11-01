<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Actions\Cart\ApplyPromocodeAction;
use App\Enums\OrderStatus;
use App\Enums\PromocodeType;
use App\Exceptions\InvalidPromocodeException;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promocode;
use App\Models\PromocodeUsage;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Отменённый заказ не расходует лимиты промокода.
 *
 * Использование фиксируется при оформлении заказа (promocode_usages), но
 * max_uses и max_uses_per_client считают только заказы, которые не отменены:
 * после отмены клиент может применить тот же промокод повторно — неважно,
 * отменил ли он заказ сам, отменила ли его курьерка или админка.
 */
final class PromocodeReleaseOnCancelTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_reapply_promocode_after_cancelling_order(): void
    {
        [$user, $store, $address] = $this->seedStoreAndUser();
        $promocode = $this->createPromocode($store, ['max_uses_per_client' => 1]);
        $this->fillCart($user, $store);

        $this->actingAs($user)
            ->postJson('/api/v1/cart/promocode', ['storeId' => $store->id, 'promocode' => 'ONCE'])
            ->assertOk();

        $orderId = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
        ])->assertCreated()->json('orderId');

        $this->assertDatabaseHas('promocode_usages', [
            'promocode_id' => $promocode->id,
            'user_id' => $user->id,
            'order_id' => $orderId,
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/orders/{$orderId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->fillCart($user, $store);

        $this->actingAs($user)
            ->postJson('/api/v1/cart/promocode', ['storeId' => $store->id, 'promocode' => 'ONCE'])
            ->assertOk();

        $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
        ])->assertCreated();

        $this->assertSame(1, PromocodeUsage::query()->active()->where('user_id', $user->id)->count());
    }

    public function test_promocode_is_released_when_order_cancelled_outside_storefront(): void
    {
        [$user, $store] = $this->seedStoreAndUser();
        $promocode = $this->createPromocode($store, ['max_uses_per_client' => 1]);
        $order = $this->orderWithUsage($user, $store, $promocode, OrderStatus::Assembling);

        $this->fillCart($user, $store);
        $this->actingAs($user)
            ->postJson('/api/v1/cart/promocode', ['storeId' => $store->id, 'promocode' => 'ONCE'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Вы уже использовали этот промокод');

        // Отмена из курьерки/админки приходит через RabbitMQ: консьюмер пишет статус
        // без событий модели, поэтому освобождение не может висеть на observer'е.
        Order::withoutEvents(fn () => $order->update(['status' => OrderStatus::Cancelled]));

        $this->actingAs($user)
            ->postJson('/api/v1/cart/promocode', ['storeId' => $store->id, 'promocode' => 'ONCE'])
            ->assertOk();
    }

    public function test_delivered_order_still_consumes_per_client_limit(): void
    {
        [$user, $store] = $this->seedStoreAndUser();
        $promocode = $this->createPromocode($store, ['max_uses_per_client' => 1]);
        $this->orderWithUsage($user, $store, $promocode, OrderStatus::Delivered);
        $this->fillCart($user, $store);

        $this->expectException(InvalidPromocodeException::class);
        $this->expectExceptionMessage('Вы уже использовали этот промокод');

        app(ApplyPromocodeAction::class)->execute($user, $store->id, 'ONCE');
    }

    public function test_total_limit_ignores_cancelled_orders(): void
    {
        [$user, $store] = $this->seedStoreAndUser();
        $promocode = $this->createPromocode($store, ['max_uses' => 1, 'used_count' => 1]);

        $otherUser = User::factory()->create();
        $this->orderWithUsage($otherUser, $store, $promocode, OrderStatus::Cancelled);
        $this->fillCart($user, $store);

        app(ApplyPromocodeAction::class)->execute($user, $store->id, 'ONCE');

        $this->assertDatabaseHas('cart_promocodes', [
            'store_id' => $store->id,
            'promocode_id' => $promocode->id,
        ]);
    }

    public function test_total_limit_counts_orders_in_progress(): void
    {
        [$user, $store] = $this->seedStoreAndUser();
        $promocode = $this->createPromocode($store, ['max_uses' => 1, 'used_count' => 1]);

        $otherUser = User::factory()->create();
        $this->orderWithUsage($otherUser, $store, $promocode, OrderStatus::Pending);
        $this->fillCart($user, $store);

        $this->expectException(InvalidPromocodeException::class);
        $this->expectExceptionMessage('Промокод больше недоступен');

        app(ApplyPromocodeAction::class)->execute($user, $store->id, 'ONCE');
    }

    public function test_platform_wide_usage_is_released_only_when_whole_client_order_cancelled(): void
    {
        $this->enableMarketplaceMode();

        [$user, $store] = $this->seedStoreAndUser();
        $otherStore = Store::factory()->create();
        $promocode = $this->createPromocode(null, [
            'scope' => Promocode::SCOPE_PLATFORM,
            'max_uses_per_client' => 1,
        ]);

        // Platform-wide использование привязано к первому sub-order client-order'а.
        $first = $this->orderWithUsage($user, $store, $promocode, OrderStatus::Cancelled, 'client-order-1');
        $second = Order::factory()->for($user)->for($otherStore)->create([
            'status' => OrderStatus::Assembling,
            'client_order_id' => 'client-order-1',
        ]);
        $this->fillCart($user, $store);

        try {
            app(ApplyPromocodeAction::class)->execute($user, null, 'ONCE');
            $this->fail('Промокод применился, хотя второй sub-order ещё в работе');
        } catch (InvalidPromocodeException $e) {
            $this->assertSame('Вы уже использовали этот промокод', $e->getMessage());
        }

        $second->update(['status' => OrderStatus::Cancelled]);

        app(ApplyPromocodeAction::class)->execute($user, null, 'ONCE');

        $this->assertDatabaseHas('cart_promocodes', [
            'store_id' => null,
            'promocode_id' => $promocode->id,
        ]);
        $this->assertSame(OrderStatus::Cancelled, $first->fresh()->status);
    }

    /**
     * @return array{0: User, 1: Store, 2: Address}
     */
    private function seedStoreAndUser(): array
    {
        $user = User::factory()->create();
        $store = Store::factory()->create([
            'min_order_amount' => null,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);
        $address = Address::factory()->active()->for($user)->create();

        return [$user, $store, $address];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createPromocode(?Store $store, array $attributes): Promocode
    {
        return Promocode::factory()->create(array_merge([
            'code' => 'ONCE',
            'type' => PromocodeType::Percent,
            'value' => 10,
            'store_id' => $store?->id,
            'scope' => Promocode::SCOPE_STORE,
            'is_active' => true,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'max_uses' => null,
            'max_uses_per_client' => null,
            'used_count' => 0,
        ], $attributes));
    }

    private function fillCart(User $user, Store $store): void
    {
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 1000,
            'price_old' => null,
            'quantity' => 10,
            'quantity_step' => 1,
        ]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 1000,
        ]);
    }

    private function orderWithUsage(
        User $user,
        Store $store,
        Promocode $promocode,
        OrderStatus $status,
        ?string $clientOrderId = null,
    ): Order {
        $order = Order::factory()->for($user)->for($store)->create([
            'status' => $status,
            'client_order_id' => $clientOrderId,
        ]);

        PromocodeUsage::create([
            'promocode_id' => $promocode->id,
            'user_id' => $user->id,
            'order_id' => $order->id,
            'used_at' => now()->subHour(),
        ]);

        return $order;
    }

    private function enableMarketplaceMode(): void
    {
        Cache::flush();
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
