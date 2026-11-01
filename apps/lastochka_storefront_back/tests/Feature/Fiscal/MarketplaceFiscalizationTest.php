<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentTransactionStatus;
use App\Jobs\FiscalizeSaleJob;
use App\Models\IntegrationConfig;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Store;
use App\Models\User;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

/**
 * GAP-1 fix: marketplace client-order — единственный online-capture путь —
 * `PaymentService::captureClientOrder()`. Consolidated hold живёт с
 * `order_id=null`, поэтому `handlePaymentSucceeded` (обычный webhook-путь,
 * который дёргает dispatchAtolSaleIfNeeded для одиночных заказов) сюда никогда
 * не долетает — capture у client-order идёт синхронным HTTP-вызовом внутри
 * FinalizeClientOrderAction, не через webhook.
 *
 * Без фикса под ATOL-драйвером ни один из sub-orders консолидированного
 * заказа не получал фискального чека: YooKassa тоже чек не пробивает
 * (ReceiptBuilder::forClientOrder под ATOL возвращает null), значит чек
 * терялся полностью — GAP-1.
 *
 * Фикс: `captureClientOrder()` теперь вызывает `dispatchAtolSaleIfNeeded($order)`
 * для КАЖДОГО sub-order сразу после `publishOrderPaid($order)`, используя тот
 * же приватный helper, что и Task 8 (гейт на driver=atol + идемпотентность по
 * receipts()->where('type','payment')).
 */
final class MarketplaceFiscalizationTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    private PaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakePaymentGateway();
        $this->app->instance(PaymentGateway::class, $this->gateway);
        $this->service = $this->app->make(PaymentService::class);
    }

    private function atolConfig(): void
    {
        IntegrationConfig::create([
            'integration_type' => 'fiscal',
            'driver' => 'atol',
            'is_active' => true,
            'credentials' => ['atol' => [
                'mode' => 'test',
                'login' => 'u',
                'password' => 'p',
                'group_code' => 'grp',
            ]],
        ]);
    }

    public function test_capture_client_order_dispatches_atol_sale_job_per_sub_order_under_atol_driver(): void
    {
        Queue::fake();
        $this->atolConfig();

        $user = User::factory()->create();
        $store1 = Store::factory()->create();
        $store2 = Store::factory()->create();

        $clientOrderId = (string) Str::uuid();

        $order1 = Order::factory()->for($user)->for($store1)->create([
            'client_order_id' => $clientOrderId,
            'total' => 400,
        ]);
        $order2 = Order::factory()->for($user)->for($store2)->create([
            'client_order_id' => $clientOrderId,
            'total' => 700,
        ]);

        $hold = $this->service->createConsolidatedHold(
            orders: [$order1, $order2],
            totalAmount: 1100.0,
            clientOrderId: $clientOrderId,
        );

        PaymentTransaction::where('yookassa_payment_id', $hold->paymentId)
            ->update(['status' => PaymentTransactionStatus::Succeeded]);

        $result = $this->service->captureClientOrder($clientOrderId, 1100.0);

        $this->assertTrue($result->success);

        // Один FiscalizeSaleJob на КАЖДЫЙ sub-order — не один общий на client-order.
        Queue::assertPushed(FiscalizeSaleJob::class, 2);

        $order1Id = $order1->id;
        $order2Id = $order2->id;

        Queue::assertPushed(FiscalizeSaleJob::class, function (FiscalizeSaleJob $job) use ($order1Id) {
            return $this->readPrivateProperty($job, 'orderId') === $order1Id
                && abs($this->readPrivateProperty($job, 'amount') - 400.0) < 0.001;
        });
        Queue::assertPushed(FiscalizeSaleJob::class, function (FiscalizeSaleJob $job) use ($order2Id) {
            return $this->readPrivateProperty($job, 'orderId') === $order2Id
                && abs($this->readPrivateProperty($job, 'amount') - 700.0) < 0.001;
        });
    }

    public function test_capture_client_order_does_not_dispatch_atol_job_without_atol_driver(): void
    {
        Queue::fake();
        // Никакой активной фискальной интеграции — YooKassa сама пробивает чек
        // внутри платежа, повторный ATOL-джоб диспатчиться не должен.

        $user = User::factory()->create();
        $store1 = Store::factory()->create();

        $clientOrderId = (string) Str::uuid();

        $order1 = Order::factory()->for($user)->for($store1)->create([
            'client_order_id' => $clientOrderId,
            'total' => 400,
        ]);

        $hold = $this->service->createConsolidatedHold(
            orders: [$order1],
            totalAmount: 400.0,
            clientOrderId: $clientOrderId,
        );

        PaymentTransaction::where('yookassa_payment_id', $hold->paymentId)
            ->update(['status' => PaymentTransactionStatus::Succeeded]);

        $this->service->captureClientOrder($clientOrderId, 400.0);

        Queue::assertNotPushed(FiscalizeSaleJob::class);
    }

    private function readPrivateProperty(object $object, string $property): mixed
    {
        $ref = new \ReflectionProperty($object, $property);
        $ref->setAccessible(true);

        return $ref->getValue($object);
    }
}
