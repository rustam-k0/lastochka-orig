<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Contracts\PaymentGateway;
use App\DTOs\Payment\PaymentInfo;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\PaymentType;
use App\Models\Order;
use App\Models\PaymentCard;
use App\Models\PaymentTransaction;
use App\Models\Store;
use App\Models\User;
use App\Services\Payment\MockPaymentGateway;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

/**
 * Карта из оплаты заказа новой картой без вебхука ЮKassa (MID-314).
 *
 * Штатно карту сохраняет вебхук `payment.waiting_for_capture`. На стенде с
 * mock-режимом холд подтверждается сразу при создании платежа, когда карты
 * клиент ещё не ввёл, а вебхук на стенд не приходит вовсе. Список карт
 * сверяет такие оплаты со шлюзом и досохраняет карту, не трогая состояние
 * оплаты заказа.
 */
final class OrderPaymentCardSyncTest extends TestCase
{
    use RefreshDatabase;

    private const PAYMENT_ID = 'pay_test_1';

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.yookassa.mock' => false]);

        $this->gateway = new FakePaymentGateway;
        // Новая карта: платёж уходит на форму ЮKassa и создаётся в pending.
        $this->gateway->holdStatus = 'pending';
        $this->gateway->paymentInfo[self::PAYMENT_ID] = new PaymentInfo(
            paymentId: self::PAYMENT_ID,
            status: 'pending',
            amount: '500.00',
            currency: 'RUB',
            paymentMethodId: self::PAYMENT_ID,
        );
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    /** Сценарий стейджа: mock подтвердил холд до ввода карты, вебхука нет. */
    public function test_card_from_order_payment_appears_in_mock_mode(): void
    {
        $this->enableMockMode();
        $order = $this->orderPaidWithNewCard();

        $this->assertSame(PaymentTransactionStatus::Succeeded, $this->hold()->status, 'mock подтвердил холд');
        $this->assertNull($order->refresh()->payment_card_id, 'карты при создании платежа ещё нет');

        // Клиент ещё на странице ЮKassa: mock выдаёт pending за waiting_for_capture.
        $this->actingAs($order->user)
            ->getJson('/api/v1/payment-cards')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->clientPaysWithCard('4477');

        $this->actingAs($order->user)
            ->getJson('/api/v1/payment-cards')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.lastFour', '4477')
            ->assertJsonPath('data.0.isDefault', true);

        $card = PaymentCard::where('user_id', $order->user_id)->sole();
        $this->assertSame($card->id, $order->refresh()->payment_card_id);
        $this->assertSame(PaymentTransactionStatus::Succeeded, $this->hold()->status);
        $this->assertSame([], $this->gateway->callsFor('cancel'), 'холд заказа не снимается');
    }

    /** Вебхук потерян: карту сохраняем, состояние оплаты заказа не трогаем. */
    public function test_card_from_order_payment_appears_when_webhook_is_lost(): void
    {
        $order = $this->orderPaidWithNewCard();
        $this->clientPaysWithCard('4477');

        $this->actingAs($order->user)
            ->getJson('/api/v1/payment-cards')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.lastFour', '4477');

        $order->refresh();
        $this->assertNotNull($order->payment_card_id);
        $this->assertSame(PaymentTransactionStatus::Pending, $this->hold()->status);
        $this->assertNull($order->held_amount, 'подтверждение оплаты остаётся за вебхуком и сверками заказа');
    }

    public function test_order_card_is_requested_once(): void
    {
        $order = $this->orderPaidWithNewCard();
        $this->clientPaysWithCard('4477');

        $this->actingAs($order->user)->getJson('/api/v1/payment-cards')->assertOk();
        $this->gateway->calls = [];

        $this->actingAs($order->user)
            ->getJson('/api/v1/payment-cards')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame([], $this->gateway->callsFor('getPaymentInfo'));
        $this->assertSame(1, PaymentCard::where('user_id', $order->user_id)->count());
    }

    public function test_known_card_is_attached_without_duplicate(): void
    {
        $order = $this->orderPaidWithNewCard();
        $existing = PaymentCard::create([
            'user_id' => $order->user_id,
            'payment_method_id' => 'pm_old',
            'card_type' => 'MasterCard',
            'last_four' => '4477',
            'expiry_month' => '12',
            'expiry_year' => '2029',
            'is_default' => true,
        ]);

        $this->clientPaysWithCard('4477');

        $this->actingAs($order->user)
            ->getJson('/api/v1/payment-cards')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame($existing->id, $order->refresh()->payment_card_id);
        $this->assertSame(self::PAYMENT_ID, $existing->refresh()->payment_method_id, 'актуальный способ оплаты');
    }

    public function test_order_paid_with_saved_card_is_not_requested(): void
    {
        $user = User::factory()->create();
        $card = PaymentCard::create([
            'user_id' => $user->id,
            'payment_method_id' => 'pm_saved',
            'card_type' => 'Visa',
            'last_four' => '1111',
            'expiry_month' => '01',
            'expiry_year' => '2030',
            'is_default' => true,
        ]);
        $order = $this->cardOrder($user, ['payment_card_id' => $card->id]);
        $this->holdFor($order, PaymentTransactionStatus::Succeeded);

        $this->actingAs($user)->getJson('/api/v1/payment-cards')->assertOk();

        $this->assertSame([], $this->gateway->callsFor('getPaymentInfo'));
    }

    public function test_canceled_order_hold_is_not_requested(): void
    {
        $user = User::factory()->create();
        $this->holdFor($this->cardOrder($user), PaymentTransactionStatus::Canceled);

        $this->actingAs($user)->getJson('/api/v1/payment-cards')->assertOk();

        $this->assertSame([], $this->gateway->callsFor('getPaymentInfo'));
    }

    public function test_cash_order_is_not_requested(): void
    {
        $user = User::factory()->create();
        $this->holdFor($this->cardOrder($user, ['payment_type' => PaymentType::Cash]), PaymentTransactionStatus::Succeeded);

        $this->actingAs($user)->getJson('/api/v1/payment-cards')->assertOk();

        $this->assertSame([], $this->gateway->callsFor('getPaymentInfo'));
    }

    public function test_other_users_order_is_not_synced(): void
    {
        $order = $this->orderPaidWithNewCard();
        $this->clientPaysWithCard('4477');

        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/payment-cards')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertSame([], $this->gateway->callsFor('getPaymentInfo'));
        $this->assertNull($order->refresh()->payment_card_id);
    }

    public function test_stale_order_hold_is_not_requested(): void
    {
        $order = $this->orderPaidWithNewCard();
        $this->clientPaysWithCard('4477');
        $this->hold()->forceFill(['created_at' => now()->subDays(8)])->save();

        $this->actingAs($order->user)->getJson('/api/v1/payment-cards')->assertOk();

        $this->assertSame([], $this->gateway->callsFor('getPaymentInfo'));
    }

    public function test_gateway_failure_keeps_list_available(): void
    {
        $order = $this->orderPaidWithNewCard();
        $this->gateway->paymentInfoShouldThrow = true;

        $this->actingAs($order->user)
            ->getJson('/api/v1/payment-cards')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertNull($order->refresh()->payment_card_id);
    }

    // ==================== Фикстуры ====================

    private function enableMockMode(): void
    {
        config(['services.yookassa.mock' => true]);
        $this->app->instance(PaymentGateway::class, new MockPaymentGateway($this->gateway));
    }

    /** Заказ оформлен с оплатой новой картой: холд создан штатным путём. */
    private function orderPaidWithNewCard(): Order
    {
        $order = $this->cardOrder(User::factory()->create());

        $this->app->make(PaymentService::class)->createHold($order);

        return $order;
    }

    private function cardOrder(User $user, array $attributes = []): Order
    {
        return Order::factory()->for($user)->for(Store::factory())->create(array_merge([
            'payment_type' => PaymentType::Card,
            'total' => 500,
        ], $attributes));
    }

    private function holdFor(Order $order, PaymentTransactionStatus $status): void
    {
        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Hold,
            'status' => $status,
            'amount' => 500,
            'yookassa_payment_id' => self::PAYMENT_ID,
            'payment_method_id' => self::PAYMENT_ID,
        ]);
    }

    /** Клиент ввёл карту на странице ЮKassa: холд подтверждён банком. */
    private function clientPaysWithCard(string $lastFour): void
    {
        $this->gateway->paymentInfo[self::PAYMENT_ID] = new PaymentInfo(
            paymentId: self::PAYMENT_ID,
            status: 'waiting_for_capture',
            amount: '500.00',
            currency: 'RUB',
            paymentMethodId: self::PAYMENT_ID,
            cardType: 'MasterCard',
            lastFour: $lastFour,
            expiryMonth: '12',
            expiryYear: '2029',
            paid: true,
        );
    }

    private function hold(): PaymentTransaction
    {
        return PaymentTransaction::query()
            ->where('yookassa_payment_id', self::PAYMENT_ID)
            ->where('type', PaymentTransactionType::Hold)
            ->sole();
    }
}
