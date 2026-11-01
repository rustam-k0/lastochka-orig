<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Order;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\PaymentType;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\PaymentCard;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

/**
 * Проверка consolidated payment flow в marketplace-режиме.
 *
 * M.9=a (один YooKassa hold на totalAmount), M.10=b (refund через consolidated capture).
 * См. docs/design-analysis/marketplace-model.md §5.1.
 */
final class ClientOrderPaymentTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->gateway = new FakePaymentGateway();
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    public function test_store_with_card_payment_creates_single_consolidated_hold_in_marketplace_mode(): void
    {
        $this->enableMarketplaceMode();

        [$user, $address, $store1, $store2] = $this->seedCartWithTwoStores();
        $slotId = $this->getValidDeliverySlotId();

        $card = PaymentCard::create([
            'user_id' => $user->id,
            'payment_method_id' => 'pm_fake_xyz',
            'card_type' => 'visa',
            'last_four' => '1111',
            'expiry_month' => '12',
            'expiry_year' => '2030',
            'is_default' => true,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/client-orders', [
            'addressId' => $address->id,
            'paymentType' => 'card',
            'paymentCardId' => $card->id,
            'deliverySlots' => [
                ['storeId' => $store1->id, 'slotId' => $slotId],
                ['storeId' => $store2->id, 'slotId' => $slotId],
            ],
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('confirmationUrl'), 'confirmationUrl должен быть возвращён для card');

        $clientOrderId = $response->json('clientOrderId');

        // Ровно один hold-вызов gateway на totalAmount
        $holdCalls = $this->gateway->callsFor('hold');
        $this->assertCount(1, $holdCalls, 'должен быть один consolidated hold');

        $totalAmount = (int) Order::where('client_order_id', $clientOrderId)->sum('total');
        $this->assertSame((float) $totalAmount, $holdCalls[0]['args']['orderTotal']);

        // Одна запись PaymentTransaction с client_order_id и order_id=null
        $transactions = PaymentTransaction::where('client_order_id', $clientOrderId)->get();
        $this->assertCount(1, $transactions);
        $tx = $transactions->first();
        $this->assertNull($tx->order_id);
        $this->assertSame(PaymentTransactionType::Hold, $tx->type);
        $this->assertSame(PaymentTransactionStatus::Pending, $tx->status);

        // Sub-orders получают card type и held_amount
        $orders = Order::where('client_order_id', $clientOrderId)->get();
        foreach ($orders as $order) {
            $paymentType = $order->payment_type instanceof PaymentType
                ? $order->payment_type
                : PaymentType::from((string) $order->payment_type);
            $this->assertSame(PaymentType::Card, $paymentType);
            $this->assertSame($card->id, $order->payment_card_id);
            $this->assertSame((string) number_format((float) $order->total, 2, '.', ''), (string) $order->held_amount);
        }
    }

    public function test_store_with_cash_payment_does_not_create_payment_transaction(): void
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

        $response->assertOk();
        $this->assertNull($response->json('confirmationUrl'));

        $this->assertCount(0, $this->gateway->callsFor('hold'), 'cash не должен вызывать gateway');
        $this->assertSame(0, PaymentTransaction::count());
    }

    public function test_store_returns_404_in_standard_mode(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/client-orders', [])
            ->assertNotFound();

        $this->assertCount(0, $this->gateway->callsFor('hold'));
    }

    public function test_consolidated_hold_decline_webhook_exposes_reason(): void
    {
        $this->enableMarketplaceMode();

        [$user, $address, $store1, $store2] = $this->seedCartWithTwoStores();
        $slotId = $this->getValidDeliverySlotId();

        // Новая карта (без paymentCardId) → consolidated hold pending + confirmationUrl (WebView).
        $response = $this->actingAs($user)->postJson('/api/v1/client-orders', [
            'addressId' => $address->id,
            'paymentType' => 'card',
            'deliverySlots' => [
                ['storeId' => $store1->id, 'slotId' => $slotId],
                ['storeId' => $store2->id, 'slotId' => $slotId],
            ],
        ]);
        $response->assertOk();
        $clientOrderId = $response->json('clientOrderId');
        $paymentId = $this->gateway->callsFor('hold')[0]['args']['paymentId'];

        // ЮKassa отменяет консолидированный платёж из-за недостатка средств.
        $this->postJson('/api/v1/webhooks/yookassa', [
            'event' => 'payment.canceled',
            'object' => [
                'id' => $paymentId,
                'status' => 'canceled',
                'cancellation_details' => ['party' => 'payment_network', 'reason' => 'insufficient_funds'],
            ],
        ])->assertOk();

        // Агрегированный client-order отдаёт машинный код причины отказа.
        $this->actingAs($user)->getJson("/api/v1/client-orders/{$clientOrderId}")
            ->assertOk()
            ->assertJsonPath('paymentStatus', 'failed')
            ->assertJsonPath('paymentDeclineReason', 'insufficient_funds');
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
