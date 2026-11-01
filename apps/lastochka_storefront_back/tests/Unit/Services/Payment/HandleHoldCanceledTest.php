<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment;

use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Webhook payment.canceled (новая карта через WebView / pending-холд сохранённой
 * карты): причина отказа от ЮKassa должна сохраняться машинным кодом в
 * payment_transactions.decline_reason, чтобы клиент опознал кейс по коду.
 */
final class HandleHoldCanceledTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_decline_reason_from_webhook(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);

        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Hold,
            'status' => PaymentTransactionStatus::Pending,
            'amount' => 500,
            'yookassa_payment_id' => 'pay_webview_1',
        ]);

        app(PaymentService::class)->handleHoldCanceled('pay_webview_1', 'insufficient_funds');

        $tx = PaymentTransaction::where('yookassa_payment_id', 'pay_webview_1')->firstOrFail();

        $this->assertSame(PaymentTransactionStatus::Canceled, $tx->status);
        $this->assertSame('insufficient_funds', $tx->decline_reason);
        $this->assertSame('Недостаточно средств на карте', $tx->error_message);
    }

    public function test_it_handles_webhook_without_reason(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);

        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Hold,
            'status' => PaymentTransactionStatus::Pending,
            'amount' => 500,
            'yookassa_payment_id' => 'pay_webview_2',
        ]);

        app(PaymentService::class)->handleHoldCanceled('pay_webview_2');

        $tx = PaymentTransaction::where('yookassa_payment_id', 'pay_webview_2')->firstOrFail();

        $this->assertSame(PaymentTransactionStatus::Canceled, $tx->status);
        $this->assertNull($tx->decline_reason);
    }
}
