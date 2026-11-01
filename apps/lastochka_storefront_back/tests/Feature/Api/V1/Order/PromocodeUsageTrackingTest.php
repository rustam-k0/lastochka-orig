<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Order;

use App\Actions\Order\CreateClientOrderAction;
use App\DTOs\Order\CreateClientOrderData;
use App\Enums\PromocodeType;
use App\Exceptions\InvalidPromocodeException;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartPromocode;
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
 * Фиксация использования промокода при создании заказа:
 *  1) created_order → +1 в used_count
 *  2) created_order → запись в promocode_usages с user_id и order_id
 */
final class PromocodeUsageTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_order_with_promocode_increments_used_count_and_creates_usage(): void
    {
        [$user, $store, $address] = $this->seedStoreAndUser();

        $promocode = Promocode::factory()->create([
            'code' => 'TRACKED',
            'type' => PromocodeType::Percent,
            'value' => 10,
            'store_id' => null,
            'is_active' => true,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'max_uses' => 10,
            'max_uses_per_client' => 1,
            'used_count' => 0,
        ]);

        $this->seedCartWithPromocode($user, $store, $promocode);

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
        ]);

        $response->assertCreated();
        $orderId = $response->json('orderId');

        // used_count увеличился
        $this->assertSame(1, (int) $promocode->fresh()->used_count);

        // Запись в promocode_usages создана с user_id + order_id
        $this->assertDatabaseHas('promocode_usages', [
            'promocode_id' => $promocode->id,
            'user_id' => $user->id,
            'order_id' => $orderId,
        ]);
    }

    public function test_platform_wide_promocode_usage_tracked_once_per_client_order(): void
    {
        $this->enableMarketplaceMode();

        [$user, $address] = $this->seedUserAndAddress();
        [$store1, $store2] = $this->seedTwoStoresWithItems($user);

        $promocode = Promocode::factory()->create([
            'code' => 'PLATFORM1',
            'type' => PromocodeType::Percent,
            'value' => 10,
            'store_id' => null,
            'scope' => Promocode::SCOPE_PLATFORM,
            'is_active' => true,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'max_uses' => 10,
            'max_uses_per_client' => 1,
            'used_count' => 0,
        ]);

        $cart = Cart::query()->where('user_id', $user->id)->first();
        CartPromocode::create([
            'cart_id' => $cart->id,
            'store_id' => null, // platform-wide
            'promocode_id' => $promocode->id,
        ]);

        $slotId = $this->getValidDeliverySlotId();

        /** @var CreateClientOrderAction $action */
        $action = app(CreateClientOrderAction::class);

        $result = $action->execute(new CreateClientOrderData(
            user: $user,
            addressId: $address->id,
            paymentType: 'cash',
            deliverySlots: [
                ['storeId' => $store1->id, 'slotId' => $slotId],
                ['storeId' => $store2->id, 'slotId' => $slotId],
            ],
        ));

        // Multi-store client-order → created 2 sub-orders
        $this->assertCount(2, $result['subOrderIds']);

        // used_count увеличился РОВНО на 1, а не на 2 (по числу sub-orders).
        $this->assertSame(1, (int) $promocode->fresh()->used_count);

        // Ровно одна запись в promocode_usages — привязана к первому sub-order.
        $this->assertDatabaseCount('promocode_usages', 1);
        $this->assertDatabaseHas('promocode_usages', [
            'promocode_id' => $promocode->id,
            'user_id' => $user->id,
            'order_id' => $result['subOrderIds'][0],
        ]);

        // cart_promocode (store_id=null) удалён.
        $this->assertSame(
            0,
            CartPromocode::query()
                ->where('cart_id', $cart->id)
                ->whereNull('store_id')
                ->count(),
        );
    }

    public function test_platform_wide_promocode_with_per_client_limit_rejects_second_order(): void
    {
        $this->enableMarketplaceMode();

        [$user, $address] = $this->seedUserAndAddress();
        [$store1, $store2] = $this->seedTwoStoresWithItems($user);

        $promocode = Promocode::factory()->create([
            'code' => 'ONLYONCE',
            'type' => PromocodeType::Percent,
            'value' => 10,
            'store_id' => null,
            'scope' => Promocode::SCOPE_PLATFORM,
            'is_active' => true,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'max_uses' => 10,
            'max_uses_per_client' => 1,
            'used_count' => 0,
        ]);

        $cart = Cart::query()->where('user_id', $user->id)->first();
        CartPromocode::create([
            'cart_id' => $cart->id,
            'store_id' => null,
            'promocode_id' => $promocode->id,
        ]);

        $slotId = $this->getValidDeliverySlotId();

        /** @var CreateClientOrderAction $action */
        $action = app(CreateClientOrderAction::class);

        // Первый client-order — OK, используется одно из max_uses_per_client.
        $action->execute(new CreateClientOrderData(
            user: $user,
            addressId: $address->id,
            paymentType: 'cash',
            deliverySlots: [
                ['storeId' => $store1->id, 'slotId' => $slotId],
                ['storeId' => $store2->id, 'slotId' => $slotId],
            ],
        ));

        $this->assertSame(1, (int) $promocode->fresh()->used_count);

        // Повторная попытка применить тот же код тем же юзером — ApplyPromocodeAction
        // бьёт при повторном apply из-за per-client лимита.
        [$store1, $store2] = $this->seedTwoStoresWithItems($user);

        $apply = app(\App\Actions\Cart\ApplyPromocodeAction::class);

        $this->expectException(InvalidPromocodeException::class);
        $apply->execute($user, null, 'ONLYONCE');
    }

    public function test_create_order_without_promocode_does_not_create_usage(): void
    {
        [$user, $store, $address] = $this->seedStoreAndUser();

        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 1000,
            'price_old' => null,
            'quantity' => 10,
        ]);

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 1000,
        ]);

        $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
        ])->assertCreated();

        $this->assertDatabaseCount('promocode_usages', 0);
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

    private function seedCartWithPromocode(User $user, Store $store, Promocode $promocode): void
    {
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 1000,
            'price_old' => null,
            'quantity' => 10,
        ]);

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 1000,
        ]);
        CartPromocode::create([
            'cart_id' => $cart->id,
            'store_id' => $store->id,
            'promocode_id' => $promocode->id,
        ]);
    }

    /**
     * @return array{0: User, 1: Address}
     */
    private function seedUserAndAddress(): array
    {
        $user = User::factory()->create();
        $address = Address::factory()->active()->for($user)->create();

        return [$user, $address];
    }

    /**
     * @return array{0: Store, 1: Store}
     */
    private function seedTwoStoresWithItems(User $user): array
    {
        $store1 = Store::factory()->create([
            'min_order_amount' => null,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);
        $store2 = Store::factory()->create([
            'min_order_amount' => null,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);

        $cat1 = Category::factory()->forStore($store1)->create();
        $cat2 = Category::factory()->forStore($store2)->create();

        $p1 = Product::factory()->for($store1)->inCategory($cat1)->create([
            'price' => 500,
            'price_old' => null,
            'quantity' => 10,
        ]);
        $p2 = Product::factory()->for($store2)->inCategory($cat2)->create([
            'price' => 700,
            'price_old' => null,
            'quantity' => 10,
        ]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);

        CartItem::factory()->for($cart)->for($p1)->create([
            'store_id' => $store1->id,
            'quantity' => 1,
            'price' => 500,
        ]);
        CartItem::factory()->for($cart)->for($p2)->create([
            'store_id' => $store2->id,
            'quantity' => 1,
            'price' => 700,
        ]);

        return [$store1, $store2];
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
