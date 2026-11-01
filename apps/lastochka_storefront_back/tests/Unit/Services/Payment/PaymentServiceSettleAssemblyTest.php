<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment;

use App\Contracts\PaymentGateway;
use App\Enums\OrderStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\PaymentType;
use App\Enums\ShippingMethod;
use App\Jobs\SendSurchargeRequiredNotification;
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

final class PaymentServiceSettleAssemblyTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    /** Card-заказ с succeeded hold на $held и сохранённой картой. */
    private function heldOrder(float $held): Order
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
            'subtotal' => $held, 'total' => $held, 'held_amount' => $held,
            'items_count' => 1, 'shipping_method' => ShippingMethod::Courier,
            'payment_card_id' => $card->id,
        ]);
        PaymentTransaction::create([
            'order_id' => $order->id, 'type' => PaymentTransactionType::Hold,
            'status' => PaymentTransactionStatus::Succeeded, 'amount' => $held,
            'yookassa_payment_id' => 'pay_hold_1', 'payment_method_id' => 'pm_test',
        ]);

        return $order->fresh();
    }

    public function test_within_hold_captures_grand_total(): void
    {
        $order = $this->heldOrder(5000);

        $result = app(PaymentService::class)->settleAssemblyPayment($order, 4500.0);

        $this->assertTrue($result->success);
        $this->assertCount(1, $this->gateway->callsFor('capture'));
        $this->assertSame(4500.0, $this->gateway->callsFor('capture')[0]['args']['amount']);
        $this->assertCount(0, $this->gateway->callsFor('charge'));
        $this->assertTrue((bool) $order->fresh()->is_paid);
    }

    public function test_exceeding_hold_charges_extra_first_then_captures_hold(): void
    {
        $order = $this->heldOrder(5000);

        $result = app(PaymentService::class)->settleAssemblyPayment($order, 6000.0);

        $this->assertTrue($result->success);
        $charges = $this->gateway->callsFor('charge');
        $captures = $this->gateway->callsFor('capture');
        $this->assertCount(1, $charges);
        $this->assertSame(1000.0, $charges[0]['args']['amount']);
        $this->assertCount(1, $captures);
        $this->assertSame(5000.0, $captures[0]['args']['amount']);
        $this->assertNull($order->fresh()->surcharge_amount);
    }

    public function test_no_hold_order_charges_full_and_marks_paid(): void
    {
        // Card-заказ без pre-auth холда: вся сумма собирается доплатой,
        // is_paid обязан выставиться локально (chargeExtra его не ставит).
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
            'subtotal' => 1000, 'total' => 1000, 'held_amount' => 0,
            'items_count' => 1, 'shipping_method' => ShippingMethod::Courier,
            'payment_card_id' => $card->id,
        ]);

        $result = app(PaymentService::class)->settleAssemblyPayment($order->fresh(), 1000.0);

        $this->assertTrue($result->success);
        $this->assertCount(1, $this->gateway->callsFor('charge'));
        $this->assertSame(1000.0, $this->gateway->callsFor('charge')[0]['args']['amount']);
        $this->assertCount(0, $this->gateway->callsFor('capture'));
        $this->assertTrue((bool) $order->fresh()->is_paid);
    }

    public function test_failed_extra_charge_leaves_hold_untouched_and_marks_awaiting(): void
    {
        Queue::fake([SendSurchargeRequiredNotification::class]);
        $this->gateway->chargeShouldFail = true;
        $order = $this->heldOrder(5000);

        $result = app(PaymentService::class)->settleAssemblyPayment($order, 6000.0);

        $this->assertFalse($result->success);
        // Ключевой инвариант порядка: capture НЕ вызывался, холд цел
        $this->assertCount(0, $this->gateway->callsFor('capture'));
        $fresh = $order->fresh();
        $this->assertSame(1000.0, (float) $fresh->surcharge_amount);
        $this->assertNotNull($fresh->surcharge_expires_at);
        $this->assertFalse((bool) $fresh->is_paid);
        Queue::assertPushed(SendSurchargeRequiredNotification::class);
    }

    public function test_retry_after_topup_succeeds_and_clears_surcharge(): void
    {
        Queue::fake([SendSurchargeRequiredNotification::class]);
        $this->gateway->chargeShouldFail = true;
        $order = $this->heldOrder(5000);
        app(PaymentService::class)->settleAssemblyPayment($order, 6000.0);

        $this->gateway->chargeShouldFail = false;
        $result = app(PaymentService::class)->settleAssemblyPayment($order->fresh(), 6000.0);

        $this->assertTrue($result->success);
        $this->assertNull($order->fresh()->surcharge_amount);
        $this->assertNull($order->fresh()->surcharge_expires_at);
    }

    /**
     * Шов с endpoint (Task 5): после провала доплаты стоит surcharge_amount (402).
     * Ретрай, где доплата УСПЕШНА, но capture упал, обязан ОЧИСТИТЬ стейл
     * surcharge_amount (иначе endpoint снова отдаст 402 «доплатите», хотя деньги
     * уже списаны) и оставить только surcharge_expires_at — дискриминатор 503 retry.
     */
    public function test_successful_extra_charge_clears_stale_surcharge_before_capture(): void
    {
        Queue::fake([SendSurchargeRequiredNotification::class]);

        // (1) доплата упала → «ожидает доплату»
        $this->gateway->chargeShouldFail = true;
        $order = $this->heldOrder(5000);
        app(PaymentService::class)->settleAssemblyPayment($order, 6000.0);
        $this->assertSame(1000.0, (float) $order->fresh()->surcharge_amount);

        // (2) доплата прошла, но capture холда упал → НЕ «ожидает доплату», а retry_pending
        $this->gateway->chargeShouldFail = false;
        $this->gateway->captureShouldFail = true;
        $result = app(PaymentService::class)->settleAssemblyPayment($order->fresh(), 6000.0);

        $this->assertFalse($result->success);
        $fresh = $order->fresh();
        $this->assertNull($fresh->surcharge_amount);                  // стейл amount очищен
        $this->assertNotNull($fresh->surcharge_expires_at);           // дискриминатор 503
        $this->assertCount(2, $this->gateway->callsFor('charge'));    // fail + success

        // (3) capture ретраится и проходит → доплата НЕ дублируется, всё чисто
        $this->gateway->captureShouldFail = false;
        $second = app(PaymentService::class)->settleAssemblyPayment($order->fresh(), 6000.0);

        $this->assertTrue($second->success);
        $this->assertCount(2, $this->gateway->callsFor('charge'));    // всё ещё 2
        $this->assertTrue((bool) $order->fresh()->is_paid);
        $this->assertNull($order->fresh()->surcharge_amount);
        $this->assertNull($order->fresh()->surcharge_expires_at);
    }

    /**
     * Прод-инцидент 19.09.2026 (заказ storefront 336 / djami 336-1): холд
     * 2657.60, итог сборки 2657.69 — весовая позиция пересчиталась по
     * фактическому весу. Система пыталась доплатить 9 копеек, ЮKassa
     * отбивала «The payment amount is smaller than the minimum payment
     * limit» (минимальный платёж — 1 ₽), сборщик 10 раз жал «Завершить»
     * и заказ стоял в магазине. Недобор меньше минимального платежа
     * шлюза не списывается вовсе: капчурим холд, копейки прощаем.
     */
    public function test_sub_minimum_shortfall_skips_charge_and_captures_hold(): void
    {
        $order = $this->heldOrder(2657.60);

        $result = app(PaymentService::class)->settleAssemblyPayment($order, 2657.69);

        $this->assertTrue($result->success);
        $this->assertCount(0, $this->gateway->callsFor('charge'));
        $captures = $this->gateway->callsFor('capture');
        $this->assertCount(1, $captures);
        $this->assertSame(2657.60, $captures[0]['args']['amount']);
        $fresh = $order->fresh();
        $this->assertTrue((bool) $fresh->is_paid);
        $this->assertNull($fresh->surcharge_amount);
        $this->assertNull($fresh->surcharge_expires_at);
    }

    /**
     * Повторный вызов после прощённого копеечного недобора (ретрай сборщика,
     * обрыв связи) обязан отвечать успехом, не трогая шлюз: холд уже закрыт,
     * второй capture по тому же платежу ЮKassa отклонит.
     */
    public function test_sub_minimum_shortfall_settle_is_idempotent(): void
    {
        $order = $this->heldOrder(2657.60);
        app(PaymentService::class)->settleAssemblyPayment($order, 2657.69);

        $result = app(PaymentService::class)->settleAssemblyPayment($order->fresh(), 2657.69);

        $this->assertTrue($result->success);
        $this->assertCount(1, $this->gateway->callsFor('capture'));
        $this->assertCount(0, $this->gateway->callsFor('charge'));
    }

    /** Граница: ровно минимальный платёж (1 ₽) шлюз принимает — доплачиваем. */
    public function test_shortfall_equal_to_minimum_is_charged(): void
    {
        $order = $this->heldOrder(5000);

        $result = app(PaymentService::class)->settleAssemblyPayment($order, 5001.0);

        $this->assertTrue($result->success);
        $charges = $this->gateway->callsFor('charge');
        $this->assertCount(1, $charges);
        $this->assertSame(1.0, $charges[0]['args']['amount']);
        $this->assertSame(5000.0, $this->gateway->callsFor('capture')[0]['args']['amount']);
    }

    /**
     * Заказ без холда (вся сумма собирается доплатой) копеечным быть не может,
     * но если холд не подтвердился — прощать нечего: сумма уходит в шлюз
     * целиком, а не режется порогом.
     */
    public function test_no_hold_order_charges_full_amount_despite_minimum_guard(): void
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
            'subtotal' => 700, 'total' => 700, 'held_amount' => 0,
            'items_count' => 1, 'shipping_method' => ShippingMethod::Courier,
            'payment_card_id' => $card->id,
        ]);

        $result = app(PaymentService::class)->settleAssemblyPayment($order->fresh(), 700.0);

        $this->assertTrue($result->success);
        $this->assertSame(700.0, $this->gateway->callsFor('charge')[0]['args']['amount']);
    }

    public function test_cash_order_is_noop_success(): void
    {
        $order = $this->heldOrder(5000);
        $order->update(['payment_type' => PaymentType::Cash]);

        $result = app(PaymentService::class)->settleAssemblyPayment($order->fresh(), 6000.0);

        $this->assertTrue($result->success);
        $this->assertCount(0, $this->gateway->callsFor('capture'));
        $this->assertCount(0, $this->gateway->callsFor('charge'));
    }

    public function test_already_captured_is_idempotent_success(): void
    {
        $order = $this->heldOrder(5000);
        PaymentTransaction::create([
            'order_id' => $order->id, 'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded, 'amount' => 6000,
            'yookassa_payment_id' => 'pay_hold_1',
        ]);

        $result = app(PaymentService::class)->settleAssemblyPayment($order->fresh(), 6000.0);

        $this->assertTrue($result->success);
        $this->assertCount(0, $this->gateway->callsFor('capture'));
    }

    /**
     * Кейс из ТЗ-уточнения: доплата списалась, связь/ЮKassa упала,
     * capture холда не прошёл. Повторное нажатие «Списать оплату» НЕ должно
     * списывать доплату второй раз — только докапчурить холд.
     */
    public function test_capture_failure_after_extra_charge_is_retry_safe(): void
    {
        Queue::fake([SendSurchargeRequiredNotification::class]);
        $this->gateway->captureShouldFail = true;
        $order = $this->heldOrder(5000);

        $first = app(PaymentService::class)->settleAssemblyPayment($order, 6000.0);

        $this->assertFalse($first->success);
        $this->assertCount(1, $this->gateway->callsFor('charge'));   // доплата прошла
        $fresh = $order->fresh();
        $this->assertNull($fresh->surcharge_amount);                  // это НЕ «ожидает доплату»
        $this->assertNotNull($fresh->surcharge_expires_at);           // страховочный дедлайн стоит
        $this->assertFalse((bool) $fresh->is_paid);
        Queue::assertNotPushed(SendSurchargeRequiredNotification::class); // пуш клиенту не нужен

        // Повторное нажатие: доплата НЕ дублируется, capture ретраится и проходит
        $this->gateway->captureShouldFail = false;
        $second = app(PaymentService::class)->settleAssemblyPayment($order->fresh(), 6000.0);

        $this->assertTrue($second->success);
        $this->assertCount(1, $this->gateway->callsFor('charge'));    // всё ещё ОДНА доплата
        $this->assertCount(2, $this->gateway->callsFor('capture'));   // fail + успешный ретрай
        $this->assertSame(5000.0, $this->gateway->callsFor('capture')[1]['args']['amount']);
        $this->assertTrue((bool) $order->fresh()->is_paid);
        $this->assertNull($order->fresh()->surcharge_expires_at);     // дедлайн снят
    }

    /**
     * Отмена «полусписанного» заказа (доплата captured, холд открыт):
     * refundOrReleaseHold обязан И вернуть доплату, И снять холд.
     */
    public function test_refund_or_release_hold_returns_extra_and_releases_open_hold(): void
    {
        $order = $this->heldOrder(5000);
        PaymentTransaction::create([
            'order_id' => $order->id, 'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded, 'amount' => 1000,
            'yookassa_payment_id' => 'pay_extra_1', 'metadata' => ['extra_charge' => true],
        ]);
        $order->update(['captured_amount' => 1000]);

        app(PaymentService::class)->refundOrReleaseHold($order->fresh());

        $this->assertCount(1, $this->gateway->callsFor('refund'));
        $this->assertSame(1000.0, $this->gateway->callsFor('refund')[0]['args']['amount']);
        $this->assertCount(1, $this->gateway->callsFor('cancel'));   // холд снят
    }
}
