<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Enums\PaymentType;
use App\Jobs\FiscalizeSaleJob;
use App\Jobs\PollAtolReceiptJob;
use App\Jobs\SendReceiptReadyNotification;
use App\Models\IntegrationConfig;
use App\Models\Order;
use App\Models\PaymentReceipt;
use App\Services\Payment\Fiscal\AtolClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class FiscalizeJobsTest extends TestCase
{
    use RefreshDatabase;

    private function atol(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['mode' => 'test', 'login' => 'u', 'password' => 'p',
                'group_code' => 'grp', 'inn' => '77', 'payment_address' => 'a', 'company_email' => 'k@e.ru']]]);
    }

    public function test_sale_job_registers_and_queues_poll(): void
    {
        $this->atol();
        Queue::fake();
        Http::fake(['*/getToken' => Http::response(['token' => 'T']),
            '*/grp/sell*' => Http::response(['uuid' => 'U1', 'status' => 'wait'])]);
        $order = Order::factory()->hasItems(1)->create(['total' => 100]);

        (new FiscalizeSaleJob($order->id, PaymentType::Cash->value, 100.0))->handle(app(\App\Contracts\Fiscalizer::class));

        $this->assertDatabaseHas('payment_receipts', ['external_id' => 'U1', 'status' => 'pending']);
        Queue::assertPushed(PollAtolReceiptJob::class);
    }

    public function test_poll_job_writes_fiscal_data_on_done(): void
    {
        $this->atol();
        $order = Order::factory()->create();
        $receipt = PaymentReceipt::create(['order_id' => $order->id, 'provider' => 'atol',
            'external_id' => 'U1', 'type' => 'payment', 'status' => 'pending', 'amount' => 100, 'items' => [], 'raw' => []]);
        Http::fake(['*/getToken' => Http::response(['token' => 'T']),
            '*/grp/report/U1*' => Http::response(['status' => 'done', 'payload' => [
                'fn_number' => 'FN1', 'fiscal_document_number' => 'FD1', 'fiscal_document_attribute' => 'FP1',
                'ofd_receipt_url' => 'http://ofd/x', 'receipt_datetime' => '2026-07-09T12:00:00']])]);

        (new PollAtolReceiptJob($receipt->id, 1))->handle(app(\App\Services\Payment\Fiscal\AtolClient::class));

        $this->assertDatabaseHas('payment_receipts', ['id' => $receipt->id, 'status' => 'succeeded',
            'fiscal_storage_number' => 'FN1', 'fiscal_document_number' => 'FD1', 'fiscal_attribute' => 'FP1',
            'receipt_url' => 'http://ofd/x']);
    }

    public function test_poll_job_short_circuits_when_receipt_already_succeeded(): void
    {
        $this->atol();
        Queue::fake();
        $order = Order::factory()->create();
        $receipt = PaymentReceipt::create(['order_id' => $order->id, 'provider' => 'atol',
            'external_id' => 'U1', 'type' => 'payment', 'status' => 'succeeded', 'amount' => 100, 'items' => [], 'raw' => []]);
        Http::fake();

        (new PollAtolReceiptJob($receipt->id, 1))->handle(app(AtolClient::class));

        Http::assertNothingSent();
        Queue::assertNotPushed(SendReceiptReadyNotification::class);
    }
}
