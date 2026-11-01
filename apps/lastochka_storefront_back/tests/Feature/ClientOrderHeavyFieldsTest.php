<?php

declare(strict_types=1);

namespace Tests\Feature;

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
use Tests\TestCase;

final class ClientOrderHeavyFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_show_returns_heavy_and_joint_delivery_fields(): void
    {
        $this->enableMarketplaceMode();
        $clientOrderId = $this->createClientOrder($user);

        Order::where('client_order_id', $clientOrderId)
            ->update(['is_heavy' => true, 'couriers_count' => 2]);

        $response = $this->actingAs($user)->getJson("/api/v1/client-orders/{$clientOrderId}");

        $response->assertOk()
            ->assertJsonPath('isHeavy', true)
            ->assertJsonPath('couriersCount', 2)
            ->assertJsonPath('isJointDelivery', true);
    }

    public function test_show_is_joint_delivery_false_with_single_courier(): void
    {
        $this->enableMarketplaceMode();
        $clientOrderId = $this->createClientOrder($user);

        Order::where('client_order_id', $clientOrderId)
            ->update(['is_heavy' => true, 'couriers_count' => 1]);

        $response = $this->actingAs($user)->getJson("/api/v1/client-orders/{$clientOrderId}");

        $response->assertOk()
            ->assertJsonPath('isHeavy', true)
            ->assertJsonPath('couriersCount', 1)
            ->assertJsonPath('isJointDelivery', false);
    }

    /**
     * Создаёт marketplace client_order через боевой flow и возвращает его id.
     * $user заполняется по ссылке — тот же владелец нужен для GET деталей.
     */
    private function createClientOrder(?User &$user): string
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

        $slotId = $this->getValidDeliverySlotId();

        $created = $this->actingAs($user)->postJson('/api/v1/client-orders', [
            'addressId' => $address->id,
            'paymentType' => 'cash',
            'deliverySlots' => [
                ['storeId' => $store1->id, 'slotId' => $slotId],
                ['storeId' => $store2->id, 'slotId' => $slotId],
            ],
        ])->assertOk();

        return $created->json('clientOrderId');
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
