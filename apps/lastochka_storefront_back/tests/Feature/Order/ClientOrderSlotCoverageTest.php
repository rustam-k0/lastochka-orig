<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Actions\Order\CreateClientOrderAction;
use App\DTOs\Order\CreateClientOrderData;
use App\Enums\ShippingMethod;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Guard покрытия магазинов корзины слотами доставки (courier/pickup).
 *
 * Скрытый маркетплейс: мобилка не знает состав магазинов корзины, поэтому
 * сервер обязан отклонить сабмит, где deliverySlots не покрывают все
 * store_id корзины — иначе создастся тихий частичный заказ (товары
 * непокрытого магазина молча останутся в корзине).
 */
final class ClientOrderSlotCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->enableMarketplaceMode();
    }

    public function test_courier_rejects_when_slots_do_not_cover_all_cart_stores(): void
    {
        [$user, $address, $store1] = $this->seedCartWithTwoStores();

        /** @var CreateClientOrderAction $action */
        $action = app(CreateClientOrderAction::class);

        try {
            $action->execute(new CreateClientOrderData(
                user: $user,
                addressId: $address->id,
                paymentType: 'cash',
                deliverySlots: [
                    ['storeId' => $store1->id, 'slotId' => $this->getValidDeliverySlotId()],
                ],
            ));
            $this->fail('Expected ValidationException for uncovered cart store');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('deliverySlots', $e->errors());
            $this->assertDatabaseCount('orders', 0);
            // Корзина не тронута — ни одна позиция не списана
            $this->assertDatabaseCount('cart_items', 2);
        }
    }

    public function test_courier_rejects_slot_for_store_not_in_cart(): void
    {
        [$user, $address, $store1] = $this->seedCartWithSingleStore();
        $foreignStore = Store::factory()->create([
            'min_order_amount' => 100,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);

        $slotId = $this->getValidDeliverySlotId();

        /** @var CreateClientOrderAction $action */
        $action = app(CreateClientOrderAction::class);

        try {
            $action->execute(new CreateClientOrderData(
                user: $user,
                addressId: $address->id,
                paymentType: 'cash',
                deliverySlots: [
                    ['storeId' => $store1->id, 'slotId' => $slotId],
                    ['storeId' => $foreignStore->id, 'slotId' => $slotId],
                ],
            ));
            $this->fail('Expected ValidationException for slot of store not in cart');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('deliverySlots', $e->errors());
            $this->assertDatabaseCount('orders', 0);
        }
    }

    public function test_courier_rejects_duplicate_slots_for_same_store(): void
    {
        [$user, $address, $store1] = $this->seedCartWithSingleStore();

        /** @var CreateClientOrderAction $action */
        $action = app(CreateClientOrderAction::class);

        try {
            $action->execute(new CreateClientOrderData(
                user: $user,
                addressId: $address->id,
                paymentType: 'cash',
                deliverySlots: [
                    ['storeId' => $store1->id, 'slotId' => $this->getValidDeliverySlotId()],
                    ['storeId' => $store1->id, 'slotId' => $this->getValidDeliverySlotId() + 3600],
                ],
            ));
            $this->fail('Expected ValidationException for duplicate store in deliverySlots');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('deliverySlots', $e->errors());
            $this->assertDatabaseCount('orders', 0);
        }
    }

    public function test_pickup_rejects_when_slots_do_not_cover_all_cart_stores(): void
    {
        [$user, $address, $store1] = $this->seedCartWithTwoStores();

        /** @var CreateClientOrderAction $action */
        $action = app(CreateClientOrderAction::class);

        try {
            $action->execute(new CreateClientOrderData(
                user: $user,
                addressId: $address->id,
                paymentType: 'cash',
                deliverySlots: [
                    ['storeId' => $store1->id, 'slotId' => $this->getValidDeliverySlotId()],
                ],
                shippingMethod: ShippingMethod::Pickup,
            ));
            $this->fail('Expected ValidationException for uncovered cart store (pickup)');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('deliverySlots', $e->errors());
            $this->assertDatabaseCount('orders', 0);
        }
    }

    public function test_courier_full_coverage_creates_sub_orders_and_clears_cart(): void
    {
        [$user, $address, $store1, $store2] = $this->seedCartWithTwoStores();

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

        $this->assertCount(2, $result['subOrderIds']);
        $this->assertDatabaseCount('cart_items', 0);
    }

    /**
     * @return array{0: User, 1: Address, 2: Store, 3: Store}
     */
    private function seedCartWithTwoStores(): array
    {
        $user = User::factory()->create();
        $address = Address::factory()->active()->for($user)->create();

        $store1 = Store::factory()->create([
            'min_order_amount' => 100,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);
        $store2 = Store::factory()->create([
            'min_order_amount' => 100,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);

        $this->addCartItem($user, $store1, 300, 2);
        $this->addCartItem($user, $store2, 400, 1);

        return [$user, $address, $store1, $store2];
    }

    /**
     * @return array{0: User, 1: Address, 2: Store}
     */
    private function seedCartWithSingleStore(): array
    {
        $user = User::factory()->create();
        $address = Address::factory()->active()->for($user)->create();

        $store = Store::factory()->create([
            'min_order_amount' => 100,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);

        $this->addCartItem($user, $store, 300, 2);

        return [$user, $address, $store];
    }

    private function addCartItem(User $user, Store $store, int $price, int $quantity): CartItem
    {
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => $price,
            'quantity' => 10,
        ]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);

        return CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => $quantity,
            'price' => $price,
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
            ]
        );
        app(ModuleConfigService::class)->invalidateCache();
    }
}
