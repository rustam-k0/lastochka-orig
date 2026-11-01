<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Contracts\PaymentGateway;
use App\DTOs\Payment\PaymentInfo;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Models\PaymentCard;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Payment\MockPaymentGateway;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

/**
 * Привязка карты без вебхука ЮKassa (MID-314).
 *
 * Карта штатно сохраняется по вебхуку `payment.waiting_for_capture`. Если он
 * не дошёл (URL уведомлений тестового магазина ведёт на другой стенд, стенд
 * был недоступен), список карт сверяет незавершённые привязки со шлюзом сам,
 * и карта появляется сразу после возврата клиента со страницы ЮKassa.
 */
final class CardBindingSyncTest extends TestCase
{
    use RefreshDatabase;

    private const PAYMENT_ID = 'pay_test_1';

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.yookassa.mock' => false]);

        $this->gateway = new FakePaymentGateway;
        // Привязка всегда уходит на форму ЮKassa: платёж создаётся в pending.
        $this->gateway->holdStatus = 'pending';
        $this->gateway->paymentInfo[self::PAYMENT_ID] = $this->awaitingCard(self::PAYMENT_ID);
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    public function test_card_appears_in_list_when_webhook_is_lost(): void
    {
        $user = $this->bindCard();

        $this->clientConfirmsCard(self::PAYMENT_ID, '4477');

        $this->actingAs($user)
            ->getJson('/api/v1/payment-cards')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.lastFour', '4477')
            ->assertJsonPath('data.0.cardType', 'MasterCard')
            ->assertJsonPath('data.0.isDefault', true);

        $card = PaymentCard::where('user_id', $user->id)->sole();
        $this->assertSame(self::PAYMENT_ID, $card->payment_method_id);

        $this->assertSame(PaymentTransactionStatus::Canceled, $this->binding()->status, 'холд 1₽ снят');
        $this->assertCount(1, $this->cancelCalls(), 'деньги за проверку карты возвращены');
    }

    public function test_binding_waits_while_client_is_on_payment_page(): void
    {
        $user = $this->bindCard();

        $this->actingAs($user)
            ->getJson('/api/v1/payment-cards')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertSame(PaymentTransactionStatus::Pending, $this->binding()->status);
        $this->assertSame([], $this->cancelCalls());
    }

    public function test_canceled_binding_is_closed_without_card(): void
    {
        $user = $this->bindCard();

        $this->gateway->paymentInfo[self::PAYMENT_ID] = new PaymentInfo(
            paymentId: self::PAYMENT_ID,
            status: 'canceled',
            amount: '1.00',
            currency: 'RUB',
            paymentMethodId: self::PAYMENT_ID,
        );

        $this->actingAs($user)
            ->getJson('/api/v1/payment-cards')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertSame(PaymentTransactionStatus::Canceled, $this->binding()->status);
    }

    /**
     * Сценарий стейджа: mock-режим включён, вебхука нет. Подтверждать привязку
     * в момент создания платежа нельзя: карту клиент ещё не ввёл, данных о ней
     * у ЮKassa нет, а холд затем уже никто не обработает.
     */
    public function test_mock_mode_does_not_confirm_binding_before_card_is_entered(): void
    {
        config(['services.yookassa.mock' => true]);
        $this->app->instance(PaymentGateway::class, new MockPaymentGateway($this->gateway));

        $user = $this->bindCard();

        $this->assertSame(PaymentTransactionStatus::Pending, $this->binding()->status);
        $this->assertSame([], $this->cancelCalls(), 'непройденный платёж отменять рано');

        // Mock выдаёт pending за waiting_for_capture, но карта ещё не введена.
        $this->actingAs($user)
            ->getJson('/api/v1/payment-cards')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->assertSame(PaymentTransactionStatus::Pending, $this->binding()->status);

        $this->clientConfirmsCard(self::PAYMENT_ID, '4477');

        $this->actingAs($user)
            ->getJson('/api/v1/payment-cards')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.lastFour', '4477');

        $this->assertSame(PaymentTransactionStatus::Canceled, $this->binding()->status);
        $this->assertCount(1, $this->cancelCalls());
    }

    public function test_late_webhook_does_not_duplicate_card(): void
    {
        $user = $this->bindCard();
        $this->clientConfirmsCard(self::PAYMENT_ID, '4477');

        $this->actingAs($user)->getJson('/api/v1/payment-cards')->assertOk();

        // Вебхук доехал уже после сверки из списка карт.
        $this->app->make(PaymentService::class)->handleHoldSucceeded(self::PAYMENT_ID);

        $this->assertSame(1, PaymentCard::where('user_id', $user->id)->count());
        $this->assertCount(1, $this->cancelCalls(), 'холд снимается один раз');
        $this->assertSame(PaymentTransactionStatus::Canceled, $this->binding()->status);
    }

    public function test_binding_confirmed_by_webhook_is_not_requested_again(): void
    {
        $user = $this->bindCard();
        $this->clientConfirmsCard(self::PAYMENT_ID, '4477');

        $this->app->make(PaymentService::class)->handleHoldSucceeded(self::PAYMENT_ID);
        $this->gateway->calls = [];

        $this->actingAs($user)
            ->getJson('/api/v1/payment-cards')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame([], $this->gateway->callsFor('getPaymentInfo'));
    }

    public function test_gateway_failure_keeps_list_available(): void
    {
        $user = $this->bindCard();
        PaymentCard::create([
            'user_id' => $user->id,
            'payment_method_id' => 'pm_saved',
            'card_type' => 'Visa',
            'last_four' => '1111',
            'expiry_month' => '01',
            'expiry_year' => '2030',
            'is_default' => true,
        ]);

        $this->gateway->paymentInfoShouldThrow = true;

        $this->actingAs($user)
            ->getJson('/api/v1/payment-cards')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.lastFour', '1111');

        $this->assertSame(PaymentTransactionStatus::Pending, $this->binding()->status, 'сверим при следующем открытии');
    }

    public function test_other_users_binding_is_not_synced(): void
    {
        $owner = $this->bindCard();
        $this->clientConfirmsCard(self::PAYMENT_ID, '4477');

        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->getJson('/api/v1/payment-cards')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertSame([], $this->gateway->callsFor('getPaymentInfo'));
        $this->assertSame(PaymentTransactionStatus::Pending, $this->binding()->status);
        $this->assertSame(0, PaymentCard::where('user_id', $owner->id)->count());
    }

    public function test_stale_binding_is_not_requested(): void
    {
        $user = $this->bindCard();
        $this->clientConfirmsCard(self::PAYMENT_ID, '4477');

        // Холд по карте живёт в ЮKassa 7 дней, дальше платёж отменяется сам.
        $this->binding()->forceFill(['created_at' => now()->subDays(8)])->save();

        $this->actingAs($user)
            ->getJson('/api/v1/payment-cards')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertSame([], $this->gateway->callsFor('getPaymentInfo'));
    }

    // ==================== Фикстуры ====================

    /** Клиент запускает привязку карты через API. */
    private function bindCard(): User
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/payment-cards/bind')
            ->assertOk()
            ->assertJsonPath('confirmationUrl', $this->gateway->confirmationUrl);

        return $user;
    }

    /** Клиент ввёл карту на странице ЮKassa: холд 1₽ подтверждён банком. */
    private function clientConfirmsCard(string $paymentId, string $lastFour): void
    {
        $this->gateway->paymentInfo[$paymentId] = new PaymentInfo(
            paymentId: $paymentId,
            status: 'waiting_for_capture',
            amount: '1.00',
            currency: 'RUB',
            paymentMethodId: $paymentId,
            cardType: 'MasterCard',
            lastFour: $lastFour,
            expiryMonth: '12',
            expiryYear: '2029',
            paid: true,
        );
    }

    /** Платёж создан, клиент ещё на странице ЮKassa: данных карты нет. */
    private function awaitingCard(string $paymentId): PaymentInfo
    {
        return new PaymentInfo(
            paymentId: $paymentId,
            status: 'pending',
            amount: '1.00',
            currency: 'RUB',
            paymentMethodId: $paymentId,
        );
    }

    private function binding(): PaymentTransaction
    {
        return PaymentTransaction::query()
            ->where('yookassa_payment_id', self::PAYMENT_ID)
            ->where('type', PaymentTransactionType::Hold)
            ->sole();
    }

    /** @return list<array{method: string, args: array<string, mixed>}> */
    private function cancelCalls(): array
    {
        return $this->gateway->callsFor('cancel');
    }
}
