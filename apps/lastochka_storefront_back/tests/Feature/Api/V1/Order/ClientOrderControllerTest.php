<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Order;

use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Models\Address;
use App\Models\AddressPhoto;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class ClientOrderControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_index_returns_404_in_standard_mode(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/client-orders')
            ->assertNotFound();
    }

    public function test_store_returns_404_in_standard_mode(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/client-orders', [])
            ->assertNotFound();
    }

    public function test_store_creates_client_order_in_marketplace_mode(): void
    {
        $this->enableMarketplaceMode();

        [$user, $address, $store1, $store2] = $this->seedCartWithTwoStores();
        $slotId = $this->getValidDeliverySlotId();

        $response = $this->actingAs($user)->postJson('/api/v1/client-orders', [
            'addressId' => $address->id,
            'paymentType' => 'cash',
            'deliverySlots' => [
                ['storeId' => $store1->id, 'slotId' => $slotId],
                ['storeId' => $store2->id, 'slotId' => $slotId],
            ],
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'clientOrderId',
                'subOrderIds',
                'totalAmount',
                'consolidatedStatus',
                'confirmationUrl',
            ]);

        $clientOrderId = $response->json('clientOrderId');
        $this->assertDatabaseCount('orders', 2);
        $this->assertSame(
            2,
            Order::where('client_order_id', $clientOrderId)->count(),
        );
    }

    public function test_index_lists_marketplace_client_orders(): void
    {
        $this->enableMarketplaceMode();
        [$user, $address, $store1, $store2] = $this->seedCartWithTwoStores();
        $slotId = $this->getValidDeliverySlotId();

        $this->actingAs($user)->postJson('/api/v1/client-orders', [
            'addressId' => $address->id,
            'paymentType' => 'cash',
            'deliverySlots' => [
                ['storeId' => $store1->id, 'slotId' => $slotId],
                ['storeId' => $store2->id, 'slotId' => $slotId],
            ],
        ])->assertOk();

        $response = $this->actingAs($user)->getJson('/api/v1/client-orders');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    ['clientOrderId', 'subOrderIds', 'consolidatedStatus', 'totalAmount', 'items'],
                ],
            ]);

        $this->assertCount(1, $response->json('data'));
    }

    public function test_show_returns_aggregated_client_order(): void
    {
        $this->enableMarketplaceMode();
        [$user, $address, $store1, $store2] = $this->seedCartWithTwoStores();
        $slotId = $this->getValidDeliverySlotId();

        $created = $this->actingAs($user)->postJson('/api/v1/client-orders', [
            'addressId' => $address->id,
            'paymentType' => 'cash',
            'comment' => 'Позвонить за час',
            'deliverySlots' => [
                ['storeId' => $store1->id, 'slotId' => $slotId],
                ['storeId' => $store2->id, 'slotId' => $slotId],
            ],
        ])->assertOk();

        $clientOrderId = $created->json('clientOrderId');

        $response = $this->actingAs($user)->getJson("/api/v1/client-orders/{$clientOrderId}");

        $response->assertOk()
            ->assertJsonPath('clientOrderId', $clientOrderId)
            ->assertJsonStructure([
                'clientOrderId',
                'subOrderIds',
                'consolidatedStatus',
                'subtotal',
                'discount',
                'deliveryCost',
                'totalAmount',
                'itemsCount',
                'items',
                'createdAt',
                'shippingMethod',
                'paymentMethod',
                'paymentStatus',
                'paidAt',
                'comment',
                'recipient',
                'additionalServices',
                'vatAmount',
                'weightTotal',
                'bonusSpend',
                'bonusEarn',
                'promocode',
                'progressStatus' => ['current', 'sequence'],
                'address' => [
                    'city',
                    'street',
                    'houseNumber',
                    'apartment',
                    'entrance',
                    'floor',
                    'intercom',
                    'objectData',
                ],
                'subOrders' => [
                    [
                        'id',
                        'storeId',
                        'status',
                        'subtotal',
                        'deliveryCost',
                        'total',
                        'deliverySlot' => ['date', 'timeFrom', 'timeTo', 'display'],
                        'items',
                    ],
                ],
            ]);

        $this->assertCount(2, $response->json('subOrderIds'));
        $this->assertCount(2, $response->json('subOrders'));
        $this->assertSame('courier', $response->json('shippingMethod'));
        $this->assertSame('cash', $response->json('paymentMethod'));
        $this->assertSame('pending', $response->json('paymentStatus'));
        $this->assertNull($response->json('paidAt'));
        $this->assertSame('Позвонить за час', $response->json('comment'));
        $this->assertNull($response->json('recipient'));
        $this->assertSame([], $response->json('additionalServices'));
        $this->assertNull($response->json('vatAmount'));
        $this->assertSame('pending', $response->json('progressStatus.current'));
        $this->assertSame(
            ['pending', 'assembling', 'assembled', 'inDelivery', 'delivered'],
            $response->json('progressStatus.sequence'),
        );
    }

    public function test_show_returns_paid_payment_status_when_capture_succeeded(): void
    {
        $this->enableMarketplaceMode();
        [$user, $address, $store1, $store2] = $this->seedCartWithTwoStores();
        $slotId = $this->getValidDeliverySlotId();

        $created = $this->actingAs($user)->postJson('/api/v1/client-orders', [
            'addressId' => $address->id,
            'paymentType' => 'cash',
            'deliverySlots' => [
                ['storeId' => $store1->id, 'slotId' => $slotId],
                ['storeId' => $store2->id, 'slotId' => $slotId],
            ],
        ])->assertOk();

        $clientOrderId = $created->json('clientOrderId');
        $firstOrderId = $created->json('subOrderIds.0');

        // Симулируем consolidated capture после доставки
        $tx = PaymentTransaction::create([
            'order_id' => $firstOrderId,
            'client_order_id' => $clientOrderId,
            'type' => PaymentTransactionType::Capture->value,
            'status' => PaymentTransactionStatus::Succeeded->value,
            'amount' => 1000,
            'currency' => 'RUB',
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/client-orders/{$clientOrderId}");

        $response->assertOk()
            ->assertJsonPath('paymentStatus', 'paid')
            ->assertJsonPath('paymentMethod', 'card');

        $this->assertSame($tx->created_at->toIso8601String(), $response->json('paidAt'));
    }

    public function test_show_returns_hold_payment_status_when_only_hold_pending(): void
    {
        $this->enableMarketplaceMode();
        [$user, $address, $store1, $store2] = $this->seedCartWithTwoStores();
        $slotId = $this->getValidDeliverySlotId();

        $created = $this->actingAs($user)->postJson('/api/v1/client-orders', [
            'addressId' => $address->id,
            'paymentType' => 'cash',
            'deliverySlots' => [
                ['storeId' => $store1->id, 'slotId' => $slotId],
                ['storeId' => $store2->id, 'slotId' => $slotId],
            ],
        ])->assertOk();

        $clientOrderId = $created->json('clientOrderId');
        $firstOrderId = $created->json('subOrderIds.0');

        PaymentTransaction::create([
            'order_id' => $firstOrderId,
            'client_order_id' => $clientOrderId,
            'type' => PaymentTransactionType::Hold->value,
            'status' => PaymentTransactionStatus::Succeeded->value,
            'amount' => 1000,
            'currency' => 'RUB',
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/client-orders/{$clientOrderId}");

        $response->assertOk()
            ->assertJsonPath('paymentStatus', 'hold')
            ->assertJsonPath('paidAt', null);
    }

    public function test_show_returns_refunded_payment_status_after_refund(): void
    {
        $this->enableMarketplaceMode();
        [$user, $address, $store1, $store2] = $this->seedCartWithTwoStores();
        $slotId = $this->getValidDeliverySlotId();

        $created = $this->actingAs($user)->postJson('/api/v1/client-orders', [
            'addressId' => $address->id,
            'paymentType' => 'cash',
            'deliverySlots' => [
                ['storeId' => $store1->id, 'slotId' => $slotId],
                ['storeId' => $store2->id, 'slotId' => $slotId],
            ],
        ])->assertOk();

        $clientOrderId = $created->json('clientOrderId');
        $firstOrderId = $created->json('subOrderIds.0');

        PaymentTransaction::create([
            'order_id' => $firstOrderId,
            'client_order_id' => $clientOrderId,
            'type' => PaymentTransactionType::Refund->value,
            'status' => PaymentTransactionStatus::Succeeded->value,
            'amount' => 1000,
            'currency' => 'RUB',
        ]);

        $this->actingAs($user)->getJson("/api/v1/client-orders/{$clientOrderId}")
            ->assertOk()
            ->assertJsonPath('paymentStatus', 'refunded');
    }

    public function test_show_aggregates_weight_total_from_actual_weight(): void
    {
        $this->enableMarketplaceMode();
        [$user, $address, $store1, $store2] = $this->seedCartWithTwoStores();
        $slotId = $this->getValidDeliverySlotId();

        $created = $this->actingAs($user)->postJson('/api/v1/client-orders', [
            'addressId' => $address->id,
            'paymentType' => 'cash',
            'deliverySlots' => [
                ['storeId' => $store1->id, 'slotId' => $slotId],
                ['storeId' => $store2->id, 'slotId' => $slotId],
            ],
        ])->assertOk();

        $clientOrderId = $created->json('clientOrderId');

        // 1500г + 750г = 2.25 кг
        Order::where('client_order_id', $clientOrderId)
            ->get()
            ->each(function (Order $order, int $idx): void {
                $order->items()->update(['actual_weight' => $idx === 0 ? 1500 : 750]);
            });

        $response = $this->actingAs($user)->getJson("/api/v1/client-orders/{$clientOrderId}");

        $response->assertOk();
        $this->assertSame(2.25, $response->json('weightTotal'));
    }

    public function test_show_returns_null_weight_when_no_data_available(): void
    {
        $this->enableMarketplaceMode();
        [$user, $address, $store1, $store2] = $this->seedCartWithTwoStores();
        $slotId = $this->getValidDeliverySlotId();

        $created = $this->actingAs($user)->postJson('/api/v1/client-orders', [
            'addressId' => $address->id,
            'paymentType' => 'cash',
            'deliverySlots' => [
                ['storeId' => $store1->id, 'slotId' => $slotId],
                ['storeId' => $store2->id, 'slotId' => $slotId],
            ],
        ])->assertOk();

        $clientOrderId = $created->json('clientOrderId');

        // Зануляем actual_weight и product.weight чтобы убедиться что отдаём null
        Order::where('client_order_id', $clientOrderId)
            ->get()
            ->each(fn (Order $order) => $order->items()->update(['actual_weight' => null]));
        Product::query()->update(['weight' => null]);

        $response = $this->actingAs($user)->getJson("/api/v1/client-orders/{$clientOrderId}");

        $response->assertOk();
        $this->assertNull($response->json('weightTotal'));
    }

    public function test_show_returns_photo_id_in_address_object_data_snapshot(): void
    {
        $this->enableMarketplaceModeWithAddressObjects();

        $user = User::factory()->create();
        $address = Address::factory()->active()->object()->for($user)->create();
        $photo1 = AddressPhoto::factory()->for($address)->create(['sort_order' => 0]);
        $photo2 = AddressPhoto::factory()->for($address)->create(['sort_order' => 1]);

        [$store1, $store2] = $this->seedTwoStoresWithCart($user);
        $slotId = $this->getValidDeliverySlotId();

        $created = $this->actingAs($user)->postJson('/api/v1/client-orders', [
            'addressId' => $address->id,
            'paymentType' => 'cash',
            'deliverySlots' => [
                ['storeId' => $store1->id, 'slotId' => $slotId],
                ['storeId' => $store2->id, 'slotId' => $slotId],
            ],
        ])->assertOk();

        $clientOrderId = $created->json('clientOrderId');

        $response = $this->actingAs($user)->getJson("/api/v1/client-orders/{$clientOrderId}");

        $response->assertOk();
        $photos = $response->json('address.objectData.photos');

        $this->assertIsArray($photos);
        $this->assertCount(2, $photos);
        $this->assertSame($photo1->id, $photos[0]['id']);
        $this->assertSame($photo2->id, $photos[1]['id']);
        $this->assertArrayHasKey('url', $photos[0]);
        $this->assertArrayHasKey('mime', $photos[0]);
    }

    public function test_show_returns_pickup_stores_for_pickup_shipping(): void
    {
        $this->enableMarketplaceMode();
        [$user, $address, $store1, $store2] = $this->seedCartWithTwoStores();
        $slotId = $this->getValidDeliverySlotId();

        $created = $this->actingAs($user)->postJson('/api/v1/client-orders', [
            'addressId' => $address->id,
            'paymentType' => 'cash',
            'deliverySlots' => [
                ['storeId' => $store1->id, 'slotId' => $slotId],
                ['storeId' => $store2->id, 'slotId' => $slotId],
            ],
        ])->assertOk();

        $clientOrderId = $created->json('clientOrderId');

        Order::where('client_order_id', $clientOrderId)
            ->update(['shipping_method' => \App\Enums\ShippingMethod::Pickup->value]);

        $response = $this->actingAs($user)->getJson("/api/v1/client-orders/{$clientOrderId}");

        $response->assertOk()
            ->assertJsonPath('shippingMethod', 'pickup')
            ->assertJsonStructure([
                'subOrders' => [
                    [
                        'pickupStore' => ['id', 'name', 'address', 'description'],
                    ],
                ],
            ]);
    }

    public function test_show_returns_404_for_unknown_client_order(): void
    {
        $this->enableMarketplaceMode();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/client-orders/00000000-0000-0000-0000-000000000000')
            ->assertNotFound();
    }

    public function test_requires_auth(): void
    {
        $this->enableMarketplaceMode();

        $this->getJson('/api/v1/client-orders')->assertUnauthorized();
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

        $cat1 = Category::factory()->forStore($store1)->create();
        $cat2 = Category::factory()->forStore($store2)->create();

        $product1 = Product::factory()->for($store1)->inCategory($cat1)->create([
            'price' => 300,
            'quantity' => 10,
        ]);
        $product2 = Product::factory()->for($store2)->inCategory($cat2)->create([
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

    /**
     * @return array{0: Store, 1: Store}
     */
    private function seedTwoStoresWithCart(User $user): array
    {
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

        $cat1 = Category::factory()->forStore($store1)->create();
        $cat2 = Category::factory()->forStore($store2)->create();

        $product1 = Product::factory()->for($store1)->inCategory($cat1)->create([
            'price' => 300,
            'quantity' => 10,
        ]);
        $product2 = Product::factory()->for($store2)->inCategory($cat2)->create([
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

        return [$store1, $store2];
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

    private function enableMarketplaceModeWithAddressObjects(): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    'marketplace_mode' => ['type' => 'select', 'value' => 'marketplace'],
                    ModuleConfigService::ADDRESS_OBJECTS_KEY => [
                        'type' => 'boolean',
                        'enabled' => true,
                    ],
                ], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ],
        );
        app(ModuleConfigService::class)->invalidateCache();
    }
}
