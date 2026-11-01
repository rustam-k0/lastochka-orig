<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment;

use App\Contracts\PaymentGateway;
use App\DTOs\Payment\PaymentInfo;
use App\Enums\OrderStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\PaymentType;
use App\Enums\ShippingMethod;
use App\Jobs\FiscalizeSaleJob;
use App\Models\IntegrationConfig;
use App\Models\Order;
use App\Models\PaymentCard;
use App\Models\PaymentTransaction;
use App\Models\Store;
use App\Models\User;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

/**
 * Списание при завершении сборки, когда `held_amount` не заполнен.
 *
 * Поле проставляется только при подтверждении холда (синхронный ответ
 * `waiting_for_capture` или webhook `payment.waiting_for_capture`). Потерянный
 * webhook оставляет заказ с held_amount = null при ЖИВОМ холде у банка —
 * тогда «доплата на всю сумму» списывает деньги вторым платежом, а холд
 * продолжает морозить средства покупателя до истечения.
 */
final class PaymentServiceStaleHoldSettleTest extends TestCase
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
     * Card-заказ, у которого hold-транзакция не подтверждена
     * (status = pending, held_amount = null).
     */
    private function orderWithUnconfirmedHold(float $amount): Order
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $card = PaymentCard::create([
            'user_id' => $user->id, 'payment_method_id' => 'pm_test',
            'card_type' => 'Visa', 'last_four' => '4444',
            'expiry_month' => '12', 'expiry_year' => '2030',
        ]);
        $order = Order::create([
            'user_id' => $user->id, 'store_id' => $store->id,
            'status' => OrderStatus::Assembling, 'payment_type' => PaymentType::Card,
            'subtotal' => $amount, 'total' => $amount, 'held_amount' => null,
            'items_count' => 1, 'shipping_method' => ShippingMethod::Courier,
            'payment_card_id' => $card->id,
        ]);
        PaymentTransaction::create([
            'order_id' => $order->id, 'type' => PaymentTransactionType::Hold,
            'status' => PaymentTransactionStatus::Pending, 'amount' => $amount,
            'yookassa_payment_id' => 'pay_hold_stale', 'payment_method_id' => 'pm_test',
        ]);

        return $order->fresh();
    }

    private function enableAtol(): void
    {
        IntegrationConfig::create([
            'integration_type' => 'fiscal',
            'driver' => 'atol',
            'is_active' => true,
            'credentials' => [
                'mode' => 'test', 'ffd_version' => '1.05', 'login' => 'l',
                'password' => 'p', 'group_code' => 'g', 'inn' => '1234567890',
                'payment_address' => 'https://shop.test/', 'sno' => 'usn_income',
            ],
        ]);
    }

    /**
     * Холд жив у банка (ЮKassa отдаёт waiting_for_capture), просто webhook
     * не дошёл: списывать нужно capture'ом холда, а не вторым платежом.
     */
    public function test_live_hold_with_lost_webhook_is_captured_not_charged(): void
    {
        $order = $this->orderWithUnconfirmedHold(1000.0);

        $this->gateway->paymentInfo['pay_hold_stale'] = new PaymentInfo(
            paymentId: 'pay_hold_stale',
            status: 'waiting_for_capture',
            amount: '1000.00',
            currency: 'RUB',
            paymentMethodId: 'pm_test',
        );

        $result = app(PaymentService::class)->settleAssemblyPayment($order, 1000.0);

        $this->assertTrue($result->success);
        $this->assertCount(1, $this->gateway->callsFor('capture'));
        $this->assertSame(1000.0, $this->gateway->callsFor('capture')[0]['args']['amount']);
        // Второго платежа быть не должно — иначе двойная заморозка средств
        $this->assertCount(0, $this->gateway->callsFor('charge'));

        $fresh = $order->fresh();
        $this->assertTrue((bool) $fresh->is_paid);
        // held_amount восстановлен из фактического состояния платежа
        $this->assertSame(1000.0, (float) $fresh->held_amount);
        $this->assertSame(1000.0, (float) $fresh->captured_amount);
    }

    /**
     * Холд жив и меньше итога сборки: доплата разницы + capture холда,
     * как в обычном сценарии с подтверждённым холдом.
     */
    public function test_live_hold_smaller_than_total_charges_only_difference(): void
    {
        $order = $this->orderWithUnconfirmedHold(1000.0);

        $this->gateway->paymentInfo['pay_hold_stale'] = new PaymentInfo(
            paymentId: 'pay_hold_stale',
            status: 'waiting_for_capture',
            amount: '1000.00',
            currency: 'RUB',
            paymentMethodId: 'pm_test',
        );

        $result = app(PaymentService::class)->settleAssemblyPayment($order, 1200.0);

        $this->assertTrue($result->success);
        $charges = $this->gateway->callsFor('charge');
        $this->assertCount(1, $charges);
        $this->assertSame(200.0, $charges[0]['args']['amount']);
        $this->assertCount(1, $this->gateway->callsFor('capture'));
        $this->assertSame(1000.0, $this->gateway->callsFor('capture')[0]['args']['amount']);
    }

    /**
     * Холд действительно не состоялся (платёж завис в pending): списываем
     * одним платежом — и пробиваем чек, иначе расчёт остаётся без фискального
     * документа (единственный путь оплаты без фискализации).
     */
    public function test_dead_hold_charges_full_amount_and_fiscalizes(): void
    {
        Queue::fake([FiscalizeSaleJob::class]);
        $this->enableAtol();

        $order = $this->orderWithUnconfirmedHold(1000.0);

        $this->gateway->paymentInfo['pay_hold_stale'] = new PaymentInfo(
            paymentId: 'pay_hold_stale',
            status: 'pending',
            amount: '1000.00',
            currency: 'RUB',
            paymentMethodId: 'pm_test',
        );

        $result = app(PaymentService::class)->settleAssemblyPayment($order, 1000.0);

        $this->assertTrue($result->success);
        $this->assertCount(1, $this->gateway->callsFor('charge'));
        $this->assertSame(1000.0, $this->gateway->callsFor('charge')[0]['args']['amount']);
        $this->assertCount(0, $this->gateway->callsFor('capture'));
        $this->assertTrue((bool) $order->fresh()->is_paid);

        Queue::assertPushed(FiscalizeSaleJob::class);
    }

    /**
     * Заказ вообще без hold-транзакции (одностадийная оплата): поведение
     * прежнее — доплата на всю сумму, плюс чек.
     */
    public function test_order_without_hold_transaction_charges_and_fiscalizes(): void
    {
        Queue::fake([FiscalizeSaleJob::class]);
        $this->enableAtol();

        $user = User::factory()->create();
        $store = Store::factory()->create();
        $card = PaymentCard::create([
            'user_id' => $user->id, 'payment_method_id' => 'pm_test',
            'card_type' => 'Visa', 'last_four' => '4444',
            'expiry_month' => '12', 'expiry_year' => '2030',
        ]);
        $order = Order::create([
            'user_id' => $user->id, 'store_id' => $store->id,
            'status' => OrderStatus::Assembling, 'payment_type' => PaymentType::Card,
            'subtotal' => 800, 'total' => 800, 'held_amount' => 0,
            'items_count' => 1, 'shipping_method' => ShippingMethod::Courier,
            'payment_card_id' => $card->id,
        ]);

        $result = app(PaymentService::class)->settleAssemblyPayment($order->fresh(), 800.0);

        $this->assertTrue($result->success);
        $this->assertCount(1, $this->gateway->callsFor('charge'));
        $this->assertCount(0, $this->gateway->callsFor('capture'));
        // Платёжного id для сверки нет — в ЮKassa не ходим
        $this->assertCount(0, $this->gateway->callsFor('getPaymentInfo'));

        Queue::assertPushed(FiscalizeSaleJob::class);
    }

    /**
     * Заказ с подтверждённым холдом обычной дорогой: сверочный запрос
     * в ЮKassa не нужен, лишних вызовов быть не должно.
     */
    public function test_confirmed_hold_does_not_query_payment_info(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $order = Order::create([
            'user_id' => $user->id, 'store_id' => $store->id,
            'status' => OrderStatus::Assembling, 'payment_type' => PaymentType::Card,
            'subtotal' => 500, 'total' => 500, 'held_amount' => 500,
            'items_count' => 1, 'shipping_method' => ShippingMethod::Courier,
        ]);
        PaymentTransaction::create([
            'order_id' => $order->id, 'type' => PaymentTransactionType::Hold,
            'status' => PaymentTransactionStatus::Succeeded, 'amount' => 500,
            'yookassa_payment_id' => 'pay_hold_ok',
        ]);

        $result = app(PaymentService::class)->settleAssemblyPayment($order->fresh(), 500.0);

        $this->assertTrue($result->success);
        $this->assertCount(0, $this->gateway->callsFor('getPaymentInfo'));
        $this->assertCount(1, $this->gateway->callsFor('capture'));
    }
}
