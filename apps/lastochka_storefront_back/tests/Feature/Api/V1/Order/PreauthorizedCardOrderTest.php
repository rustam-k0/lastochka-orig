<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Order;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\PaymentCard;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

/**
 * Pre-authorization по сохранённой карте: синхронный холд ДО создания заказа.
 * При недостатке средств заказ вообще не должен попадать в БД.
 */
final class PreauthorizedCardOrderTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    /**
     * @return array{user: User, store: Store, product: Product, address: Address, cart: Cart, card: PaymentCard}
     */
    private function makeOrderContext(): array
    {
        $user = User::factory()->create(['phone' => '+79991234567']);
        $store = Store::factory()->create([
            'min_order_amount' => null,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 300,
            'quantity' => 10,
            'stock_mode' => 'fixed', // чтобы резерв реально декрементил quantity
        ]);
        $address = Address::factory()->active()->for($user)->create();

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 2,
            'price' => 300,
        ]);

        $card = PaymentCard::create([
            'user_id' => $user->id,
            'payment_method_id' => 'pm_saved_card',
            'card_type' => 'Visa',
            'last_four' => '4242',
            'expiry_month' => '12',
            'expiry_year' => '2030',
            'is_default' => true,
        ]);

        return compact('user', 'store', 'product', 'address', 'cart', 'card');
    }

    public function test_declined_preauth_does_not_create_order(): void
    {
        $ctx = $this->makeOrderContext();
        $this->gateway->holdShouldFail = true; // банк отклонил (недостаток средств)
        $this->gateway->holdDeclineReason = 'insufficient_funds';

        $response = $this->actingAs($ctx['user'])->postJson('/api/v1/orders', [
            'storeId' => $ctx['store']->id,
            'addressId' => $ctx['address']->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
            'paymentType' => 'card',
            'paymentCardId' => $ctx['card']->id,
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error', 'payment_declined')
            // Машинный код причины — клиент опознаёт «недостаточно средств» по нему.
            ->assertJsonPath('reason', 'insufficient_funds');

        // Заказ НЕ создан
        $this->assertDatabaseMissing('orders', ['user_id' => $ctx['user']->id]);
        // Товар НЕ зарезервирован (остался 10)
        $this->assertDatabaseHas('products', ['id' => $ctx['product']->id, 'quantity' => 10]);
        // Корзина НЕ очищена
        $this->assertDatabaseHas('cart_items', [
            'cart_id' => $ctx['cart']->id,
            'store_id' => $ctx['store']->id,
        ]);
        // Холд был, но capture/повторного холда нет
        $this->assertCount(1, $this->gateway->callsFor('hold'));
    }

    public function test_successful_preauth_creates_order_with_hold(): void
    {
        $ctx = $this->makeOrderContext();
        $this->gateway->holdStatus = 'waiting_for_capture'; // деньги захолдированы

        $response = $this->actingAs($ctx['user'])->postJson('/api/v1/orders', [
            'storeId' => $ctx['store']->id,
            'addressId' => $ctx['address']->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
            'paymentType' => 'card',
            'paymentCardId' => $ctx['card']->id,
        ]);

        $response->assertCreated();
        $orderId = $response->json('orderId');

        // held_amount = totalBeforeBonus = subtotal 600 + доставка 200 (FakeDeliveryClient)
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'payment_type' => 'card',
            'status' => 'pending',
            'held_amount' => 800,
        ]);

        // Холд зафиксирован как succeeded-транзакция, привязан к заказу
        $this->assertDatabaseHas('payment_transactions', [
            'order_id' => $orderId,
            'type' => PaymentTransactionType::Hold->value,
            'status' => PaymentTransactionStatus::Succeeded->value,
            'amount' => 800,
            'payment_method_id' => 'pm_saved_card',
        ]);

        // Товар зарезервирован
        $this->assertDatabaseHas('products', ['id' => $ctx['product']->id, 'quantity' => 8]);

        // Холд сделан по сохранённой карте, второго холда (createHold) не было
        $holdCalls = $this->gateway->callsFor('hold');
        $this->assertCount(1, $holdCalls);
        $this->assertSame('pm_saved_card', $holdCalls[0]['args']['paymentMethodId']);
    }

    public function test_pending_preauth_creates_order_without_held_amount(): void
    {
        $ctx = $this->makeOrderContext();
        $this->gateway->holdStatus = 'pending'; // 3DS / ждём webhook

        $response = $this->actingAs($ctx['user'])->postJson('/api/v1/orders', [
            'storeId' => $ctx['store']->id,
            'addressId' => $ctx['address']->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
            'paymentType' => 'card',
            'paymentCardId' => $ctx['card']->id,
        ]);

        $response->assertCreated();
        $orderId = $response->json('orderId');

        // Холд ещё не подтверждён → held_amount не проставлен, транзакция pending
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'held_amount' => null]);
        $this->assertDatabaseHas('payment_transactions', [
            'order_id' => $orderId,
            'type' => PaymentTransactionType::Hold->value,
            'status' => PaymentTransactionStatus::Pending->value,
        ]);
    }

    public function test_card_order_without_saved_card_uses_redirect_flow(): void
    {
        $ctx = $this->makeOrderContext();
        // paymentCardId НЕ передаём → нет сохранённой карты → pre-auth не применяется,
        // холд создаётся после заказа (redirect-флоу), возвращается confirmationUrl.
        $response = $this->actingAs($ctx['user'])->postJson('/api/v1/orders', [
            'storeId' => $ctx['store']->id,
            'addressId' => $ctx['address']->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
            'paymentType' => 'card',
        ]);

        $response->assertCreated()
            ->assertJsonPath('confirmationUrl', $this->gateway->confirmationUrl);

        $this->assertDatabaseHas('orders', [
            'id' => $response->json('orderId'),
            'payment_type' => 'card',
        ]);

        // Холд по сохранённой карте НЕ делался (paymentMethodId = null)
        $holdCalls = $this->gateway->callsFor('hold');
        $this->assertCount(1, $holdCalls);
        $this->assertNull($holdCalls[0]['args']['paymentMethodId']);
    }

    public function test_cancelling_pending_preauth_order_releases_hold(): void
    {
        $ctx = $this->makeOrderContext();
        $this->gateway->holdStatus = 'pending'; // held_amount останется NULL

        $create = $this->actingAs($ctx['user'])->postJson('/api/v1/orders', [
            'storeId' => $ctx['store']->id,
            'addressId' => $ctx['address']->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
            'paymentType' => 'card',
            'paymentCardId' => $ctx['card']->id,
        ]);
        $create->assertCreated();
        $orderId = $create->json('orderId');
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'held_amount' => null]);

        $cancel = $this->actingAs($ctx['user'])->postJson("/api/v1/orders/{$orderId}/cancel");
        $cancel->assertOk();

        // Холд снят, несмотря на held_amount=NULL (регрессия C1: раньше
        // refundOrReleaseHold гейтил снятие на held_amount > 0 → деньги зависали).
        $this->assertNotEmpty($this->gateway->callsFor('cancel'));
    }

    public function test_cash_order_does_not_preauthorize(): void
    {
        $ctx = $this->makeOrderContext();

        $response = $this->actingAs($ctx['user'])->postJson('/api/v1/orders', [
            'storeId' => $ctx['store']->id,
            'addressId' => $ctx['address']->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
            'paymentType' => 'cash',
        ]);

        $response->assertCreated();
        $this->assertCount(0, $this->gateway->callsFor('hold'));
        $this->assertDatabaseMissing('payment_transactions', [
            'order_id' => $response->json('orderId'),
        ]);
    }

    public function test_retry_payment_declined_exposes_reason_code(): void
    {
        $ctx = $this->makeOrderContext();

        // Заказ создан по redirect-флоу (новая карта), холд pending.
        $create = $this->actingAs($ctx['user'])->postJson('/api/v1/orders', [
            'storeId' => $ctx['store']->id,
            'addressId' => $ctx['address']->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
            'paymentType' => 'card',
        ]);
        $create->assertCreated();
        $orderId = $create->json('orderId');

        // Повторная оплата (модалка «Недостаточно средств» → CTA «Повторить оплату»)
        // отклонена банком с конкретной причиной.
        $this->gateway->holdShouldFail = true;
        $this->gateway->holdDeclineReason = 'insufficient_funds';

        $pay = $this->actingAs($ctx['user'])->postJson("/api/v1/orders/{$orderId}/pay");

        $pay->assertUnprocessable()
            ->assertJsonPath('reason', 'insufficient_funds');
    }

    public function test_webview_decline_webhook_exposes_reason_on_order(): void
    {
        $ctx = $this->makeOrderContext();

        // Новая карта через WebView ЮKassa: заказ создаётся, холд pending.
        $create = $this->actingAs($ctx['user'])->postJson('/api/v1/orders', [
            'storeId' => $ctx['store']->id,
            'addressId' => $ctx['address']->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
            'paymentType' => 'card',
        ]);
        $create->assertCreated();
        $orderId = $create->json('orderId');
        $paymentId = $this->gateway->callsFor('hold')[0]['args']['paymentId'];

        // ЮKassa уведомляет об отмене платежа (недостаток средств) асинхронным webhook'ом.
        $this->postJson('/api/v1/webhooks/yookassa', [
            'event' => 'payment.canceled',
            'object' => [
                'id' => $paymentId,
                'status' => 'canceled',
                'cancellation_details' => ['party' => 'payment_network', 'reason' => 'insufficient_funds'],
            ],
        ])->assertOk();

        // Клиент после возврата из WebView опрашивает заказ и видит машинный код причины.
        $this->actingAs($ctx['user'])->getJson("/api/v1/orders/{$orderId}")
            ->assertOk()
            ->assertJsonPath('data.paymentDeclineReason', 'insufficient_funds');
    }
}
