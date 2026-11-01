<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment;

use App\Models\Order;
use App\Services\Payment\YooKassaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Автоплатёж по сохранённой карте YooKassa отклоняет СИНХРОННО: HTTP 200,
 * но status='canceled' + cancellation_details.reason. Раньше hold() смотрел
 * только на HTTP-код и трактовал отказ как успех (success=true).
 */
final class YooKassaServiceHoldCanceledTest extends TestCase
{
    use RefreshDatabase;

    public function test_hold_returns_failure_on_synchronous_canceled(): void
    {
        Http::fake([
            'api.yookassa.ru/*' => Http::response([
                'id' => 'pay_declined_1',
                'status' => 'canceled',
                'cancellation_details' => ['party' => 'payment_network', 'reason' => 'insufficient_funds'],
                'payment_method' => ['id' => 'pm_1'],
            ], 200),
        ]);

        $order = new Order;
        $order->id = 7;
        $order->total = 400;

        $result = (new YooKassaService)->hold($order, 'pm_1');

        $this->assertFalse($result->success);
        $this->assertSame('canceled', $result->status);
        $this->assertSame('pay_declined_1', $result->paymentId);
        $this->assertSame('Недостаточно средств на карте', $result->errorMessage);
        // Машинный код причины — для идентификации кейса клиентом без сравнения текста.
        $this->assertSame('insufficient_funds', $result->declineReason);
    }

    public function test_hold_omits_decline_reason_when_yookassa_gives_none(): void
    {
        Http::fake([
            'api.yookassa.ru/*' => Http::response([
                'id' => 'pay_declined_2',
                'status' => 'canceled',
                'cancellation_details' => ['party' => 'payment_network', 'reason' => ''],
                'payment_method' => ['id' => 'pm_1'],
            ], 200),
        ]);

        $order = new Order;
        $order->id = 9;
        $order->total = 400;

        $result = (new YooKassaService)->hold($order, 'pm_1');

        $this->assertFalse($result->success);
        $this->assertNull($result->declineReason);
        $this->assertSame('Платёж отклонён', $result->errorMessage);
    }

    public function test_hold_succeeds_on_waiting_for_capture(): void
    {
        Http::fake([
            'api.yookassa.ru/*' => Http::response([
                'id' => 'pay_ok_1',
                'status' => 'waiting_for_capture',
                'payment_method' => ['id' => 'pm_1'],
            ], 200),
        ]);

        $order = new Order;
        $order->id = 8;
        $order->total = 400;

        $result = (new YooKassaService)->hold($order, 'pm_1');

        $this->assertTrue($result->success);
        $this->assertSame('waiting_for_capture', $result->status);
        $this->assertSame('pay_ok_1', $result->paymentId);
    }

    public function test_explicit_attribution_sets_description_metadata_and_idempotence(): void
    {
        Http::fake([
            'api.yookassa.ru/*' => Http::response([
                'id' => 'pay_preauth_1',
                'status' => 'waiting_for_capture',
                'payment_method' => ['id' => 'pm_1'],
            ], 200),
        ]);

        $order = new Order;
        $order->total = 500;

        (new YooKassaService)->hold(
            order: $order,
            paymentMethodId: 'pm_1',
            idempotenceKey: 'preauth-idem-key-1',
            description: 'Оплата заказа',
            metadata: ['preauth' => true],
        );

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return ($body['description'] ?? null) === 'Оплата заказа'
                && ($body['metadata']['preauth'] ?? null) === true
                && ($request->header('Idempotence-Key')[0] ?? null) === 'preauth-idem-key-1';
        });
    }
}
