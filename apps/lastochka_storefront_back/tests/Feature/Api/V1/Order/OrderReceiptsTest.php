<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Order;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Models\Order;
use App\Models\PaymentReceipt;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

final class OrderReceiptsTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    private function succeededPaymentRaw(string $paymentId, string $receiptId): array
    {
        return [
            'id' => $receiptId,
            'type' => 'payment',
            'payment_id' => $paymentId,
            'status' => 'succeeded',
            'fiscal_document_number' => '3986',
            'fiscal_storage_number' => '9288000100115785',
            'fiscal_attribute' => '2617603921',
            'registered_at' => '2026-06-16T15:42:00.000Z',
            'items' => [
                ['description' => 'Молоко 3.2% 1л', 'quantity' => '1.00', 'amount' => ['value' => '600.00'], 'vat_code' => 4],
            ],
            'settlements' => [['type' => 'cashless', 'amount' => ['value' => '600.00']]],
        ];
    }

    public function test_returns_receipt_with_items_fiscal_and_qr(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create();
        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 600.00,
            'yookassa_payment_id' => 'pay_1',
        ]);
        $this->gateway->receiptsByPaymentId['pay_1'] = [$this->succeededPaymentRaw('pay_1', 'rt-1')];

        $response = $this->actingAs($user)->getJson("/api/v1/orders/{$order->id}/receipts");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'payment')
            ->assertJsonPath('data.0.status', 'succeeded')
            ->assertJsonPath('data.0.total', '600.00')
            ->assertJsonPath('data.0.fiscal.fn', '9288000100115785')
            ->assertJsonPath('data.0.fiscal.fd', '3986')
            ->assertJsonPath('data.0.items.0.description', 'Молоко 3.2% 1л');

        $this->assertStringContainsString('fn=9288000100115785', $response->json('data.0.qr'));
        $this->assertStringEndsWith('&n=1', $response->json('data.0.qr'));
    }

    public function test_returns_computed_vat_per_item_and_summary(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create();
        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 720.00,
            'yookassa_payment_id' => 'pay_vat',
        ]);
        $this->gateway->receiptsByPaymentId['pay_vat'] = [[
            'id' => 'rt-vat',
            'type' => 'payment',
            'payment_id' => 'pay_vat',
            'status' => 'succeeded',
            'fiscal_document_number' => '3986',
            'fiscal_storage_number' => '9288000100115785',
            'fiscal_attribute' => '2617603921',
            'registered_at' => '2026-06-16T15:42:00.000Z',
            'items' => [
                // 20% «в том числе»: 600 × 20/120 = 100.00
                ['description' => 'Молоко 3.2% 1л', 'quantity' => '1.00', 'amount' => ['value' => '600.00'], 'vat_code' => 4],
                // 10% «в том числе»: 110 × 10/110 = 10.00
                ['description' => 'Хлеб', 'quantity' => '1.00', 'amount' => ['value' => '110.00'], 'vat_code' => 3],
                // без НДС: 0.00
                ['description' => 'Доставка', 'quantity' => '1.00', 'amount' => ['value' => '10.00'], 'vat_code' => 1],
            ],
            'settlements' => [['type' => 'cashless', 'amount' => ['value' => '720.00']]],
        ]];

        $response = $this->actingAs($user)->getJson("/api/v1/orders/{$order->id}/receipts");

        $response->assertOk()
            ->assertJsonPath('data.0.items.0.vat_rate', '20')
            ->assertJsonPath('data.0.items.0.vat_amount', '100.00')
            ->assertJsonPath('data.0.items.1.vat_rate', '10')
            ->assertJsonPath('data.0.items.1.vat_amount', '10.00')
            ->assertJsonPath('data.0.items.2.vat_rate', '0')
            ->assertJsonPath('data.0.items.2.vat_amount', '0.00');

        // Свод НДС сгруппирован по ставке (без НДС → 0.00, 10% → 10.00, 20% → 100.00).
        $vat = collect($response->json('data.0.vat'))->keyBy('vat_code');
        $this->assertSame('100.00', $vat[4]['amount']);
        $this->assertSame('20', $vat[4]['rate']);
        $this->assertSame('10.00', $vat[3]['amount']);
        $this->assertSame('10', $vat[3]['rate']);
        $this->assertSame('0.00', $vat[1]['amount']);
    }

    public function test_lazy_fallback_persists_receipt(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create();
        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 600.00,
            'yookassa_payment_id' => 'pay_1',
        ]);
        $this->gateway->receiptsByPaymentId['pay_1'] = [$this->succeededPaymentRaw('pay_1', 'rt-1')];

        $this->assertSame(0, PaymentReceipt::count());

        $this->actingAs($user)->getJson("/api/v1/orders/{$order->id}/receipts")->assertOk();

        $this->assertSame(1, PaymentReceipt::where('yookassa_receipt_id', 'rt-1')->count());
    }

    public function test_pending_receipt_has_null_fiscal_and_qr(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create();
        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 600.00,
            'yookassa_payment_id' => 'pay_1',
        ]);
        $this->gateway->receiptsByPaymentId['pay_1'] = [[
            'id' => 'rt-pending',
            'type' => 'payment',
            'payment_id' => 'pay_1',
            'status' => 'pending',
            'items' => [['description' => 'Товар', 'quantity' => '1.00', 'amount' => ['value' => '600.00'], 'vat_code' => 4]],
            'settlements' => [['type' => 'cashless', 'amount' => ['value' => '600.00']]],
        ]];

        $response = $this->actingAs($user)->getJson("/api/v1/orders/{$order->id}/receipts");

        $response->assertOk()
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.fiscal', null)
            ->assertJsonPath('data.0.qr', null)
            ->assertJsonPath('data.0.items.0.description', 'Товар');
    }

    public function test_payment_and_refund_return_two_receipts(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create();
        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 600.00,
            'yookassa_payment_id' => 'pay_1',
        ]);
        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Refund,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 200.00,
            'yookassa_payment_id' => 'pay_1',
            'yookassa_refund_id' => 'ref_1',
        ]);
        $this->gateway->receiptsByPaymentId['pay_1'] = [$this->succeededPaymentRaw('pay_1', 'rt-1')];
        $this->gateway->receiptsByRefundId['ref_1'] = [[
            'id' => 'rt-refund',
            'type' => 'refund',
            'refund_id' => 'ref_1',
            'status' => 'succeeded',
            'fiscal_document_number' => '3990',
            'fiscal_storage_number' => '9288000100115785',
            'fiscal_attribute' => '111',
            'registered_at' => '2026-06-16T16:00:00.000Z',
            'items' => [['description' => 'Возврат', 'quantity' => '1.00', 'amount' => ['value' => '200.00'], 'vat_code' => 4]],
            'settlements' => [['type' => 'cashless', 'amount' => ['value' => '200.00']]],
        ]];

        $response = $this->actingAs($user)->getJson("/api/v1/orders/{$order->id}/receipts");

        $response->assertOk()->assertJsonCount(2, 'data');
        $types = collect($response->json('data'))->pluck('type')->all();
        $this->assertContains('payment', $types);
        $this->assertContains('refund', $types);
    }

    public function test_other_users_order_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $order = Order::factory()->for($owner)->create();

        $this->actingAs($stranger)
            ->getJson("/api/v1/orders/{$order->id}/receipts")
            ->assertForbidden();
    }

    public function test_order_without_payments_returns_empty(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create();

        $this->actingAs($user)
            ->getJson("/api/v1/orders/{$order->id}/receipts")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_webhook_payment_succeeded_syncs_receipt(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create(['is_paid' => false]);
        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Hold,
            'status' => PaymentTransactionStatus::Pending,
            'amount' => 600.00,
            'yookassa_payment_id' => 'pay_hook',
        ]);
        $this->gateway->receiptsByPaymentId['pay_hook'] = [$this->succeededPaymentRaw('pay_hook', 'rt-hook')];

        $this->postJson('/api/v1/webhooks/yookassa', [
            'event' => 'payment.succeeded',
            'object' => ['id' => 'pay_hook'],
        ])->assertOk();

        $this->assertDatabaseHas('payment_receipts', [
            'yookassa_receipt_id' => 'rt-hook',
            'order_id' => $order->id,
        ]);
    }
}
