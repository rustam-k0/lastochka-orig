<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Actions\Order\CreateClientOrderAction;
use App\DTOs\Order\CreateClientOrderData;
use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CreateClientOrderActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->enableMarketplaceMode();
    }

    public function test_creates_sub_orders_with_shared_client_order_id(): void
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

        $this->assertArrayHasKey('clientOrderId', $result);
        $this->assertArrayHasKey('subOrderIds', $result);
        $this->assertArrayHasKey('totalAmount', $result);
        $this->assertArrayHasKey('consolidatedStatus', $result);
        $this->assertArrayHasKey('confirmationUrl', $result);

        $this->assertCount(2, $result['subOrderIds']);
        $this->assertNull($result['confirmationUrl'], 'MVP: consolidated hold deferred to P2');
        $this->assertSame(OrderStatus::Pending->value, $result['consolidatedStatus']);

        $clientOrderId = $result['clientOrderId'];
        $orders = Order::query()->where('client_order_id', $clientOrderId)->get();
        $this->assertCount(2, $orders);
        foreach ($orders as $order) {
            $this->assertSame($user->id, $order->user_id);
            $this->assertSame($clientOrderId, $order->client_order_id);
            $paymentType = $order->payment_type instanceof PaymentType
                ? $order->payment_type->value
                : (string) $order->payment_type;
            $this->assertSame('cash', $paymentType);
        }

        // Total = сумма sub-orders. ClientOrderResource отдаёт totalAmount
        // как round((float) …, 2) — сравниваем во float, приведение к int
        // ломало бы assertSame на любой сумме с копейками.
        $this->assertIsFloat($result['totalAmount']);
        $this->assertEqualsWithDelta(
            round((float) $orders->sum('total'), 2),
            $result['totalAmount'],
            0.01,
        );
    }

    public function test_courier_client_order_with_pickup_only_product_is_rejected(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->active()->for($user)->create();

        $store = Store::factory()->create([
            'min_order_amount' => 0,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);

        $product = Product::factory()->for($store)->create([
            'sku' => 'WINE-MARKETPLACE',
            'price' => 500,
            'quantity' => 10,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
            'pickup_only' => true,
        ]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 500,
        ]);

        $slotId = $this->getValidDeliverySlotId();

        /** @var CreateClientOrderAction $action */
        $action = app(CreateClientOrderAction::class);

        $this->expectException(ValidationException::class);

        try {
            $action->execute(new CreateClientOrderData(
                user: $user,
                addressId: $address->id,
                paymentType: 'cash',
                deliverySlots: [
                    ['storeId' => $store->id, 'slotId' => $slotId],
                ],
            ));
        } catch (ValidationException $e) {
            $data = $e->response->getData(true);
            $this->assertArrayHasKey('shippingMethod', $data['errors']);
            $this->assertDatabaseCount('orders', 0);

            throw $e;
        }
    }

    public function test_idempotency_returns_same_client_order_on_duplicate_key(): void
    {
        [$user, $address, $store1, $store2] = $this->seedCartWithTwoStores();

        $slotId = $this->getValidDeliverySlotId();

        /** @var CreateClientOrderAction $action */
        $action = app(CreateClientOrderAction::class);

        $data = new CreateClientOrderData(
            user: $user,
            addressId: $address->id,
            paymentType: 'cash',
            deliverySlots: [
                ['storeId' => $store1->id, 'slotId' => $slotId],
                ['storeId' => $store2->id, 'slotId' => $slotId],
            ],
            idempotencyKey: 'test-key-123',
        );

        $first = $action->execute($data);

        // Повторно положим товары в корзину — иначе CreateOrderAction во втором вызове упадёт
        $this->seedCartWithTwoStores($user, $address, $store1, $store2);

        $second = $action->execute($data);

        $this->assertSame($first['clientOrderId'], $second['clientOrderId']);
        $this->assertSame($first['subOrderIds'], $second['subOrderIds']);
    }

    public function test_consolidate_statuses_all_pending(): void
    {
        $result = CreateClientOrderAction::consolidateStatuses([
            OrderStatus::Pending,
            OrderStatus::Pending,
        ]);

        $this->assertSame(OrderStatus::Pending, $result);
    }

    public function test_consolidate_statuses_returns_min_progress(): void
    {
        $result = CreateClientOrderAction::consolidateStatuses([
            OrderStatus::Delivered,
            OrderStatus::Assembling,
            OrderStatus::InDelivery,
        ]);

        $this->assertSame(OrderStatus::Assembling, $result, 'min progress = Assembling (1)');
    }

    public function test_consolidate_statuses_ignores_cancelled_when_other_active(): void
    {
        $result = CreateClientOrderAction::consolidateStatuses([
            OrderStatus::Cancelled,
            OrderStatus::Assembling,
        ]);

        $this->assertSame(OrderStatus::Assembling, $result);
    }

    public function test_consolidate_statuses_returns_cancelled_when_all_cancelled(): void
    {
        $result = CreateClientOrderAction::consolidateStatuses([
            OrderStatus::Cancelled,
            OrderStatus::Cancelled,
        ]);

        $this->assertSame(OrderStatus::Cancelled, $result);
    }

    public function test_consolidate_statuses_empty_array(): void
    {
        $result = CreateClientOrderAction::consolidateStatuses([]);

        $this->assertSame(OrderStatus::Pending, $result);
    }

    /**
     * @return array{0: User, 1: Address, 2: Store, 3: Store}
     */
    private function seedCartWithTwoStores(
        ?User $user = null,
        ?Address $address = null,
        ?Store $store1 = null,
        ?Store $store2 = null
    ): array {
        $user ??= User::factory()->create();
        $address ??= Address::factory()->active()->for($user)->create();

        $store1 ??= Store::factory()->create([
            'min_order_amount' => 100,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);
        $store2 ??= Store::factory()->create([
            'min_order_amount' => 100,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);

        $category1 = Category::factory()->forStore($store1)->create();
        $category2 = Category::factory()->forStore($store2)->create();

        $product1 = Product::factory()->for($store1)->inCategory($category1)->create([
            'price' => 300,
            'quantity' => 10,
        ]);
        $product2 = Product::factory()->for($store2)->inCategory($category2)->create([
            'price' => 400,
            'quantity' => 10,
        ]);

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

        return [$user, $address, $store1, $store2];
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
