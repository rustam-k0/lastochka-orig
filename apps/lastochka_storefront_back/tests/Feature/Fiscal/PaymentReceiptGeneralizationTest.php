<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Enums\ReceiptProvider;
use App\Models\Order;
use App\Models\PaymentReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PaymentReceiptGeneralizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_stores_provider_external_id_and_url(): void
    {
        $order = Order::factory()->create();
        $receipt = PaymentReceipt::create([
            'order_id' => $order->id,
            'provider' => ReceiptProvider::Atol,
            'external_id' => 'atol-uuid-1',
            'type' => 'payment',
            'status' => 'succeeded',
            'receipt_url' => 'https://ofd.example/r/1',
            'amount' => 100.00,
            'items' => [],
            'raw' => [],
        ]);

        $this->assertSame(ReceiptProvider::Atol, $receipt->fresh()->provider);
        $this->assertSame('atol-uuid-1', $receipt->fresh()->external_id);
        $this->assertSame('https://ofd.example/r/1', $receipt->fresh()->receipt_url);
    }

    public function test_provider_and_external_id_are_unique_together(): void
    {
        $order = Order::factory()->create();
        $base = ['order_id' => $order->id, 'provider' => ReceiptProvider::Atol,
            'external_id' => 'dup', 'type' => 'payment', 'status' => 'pending',
            'amount' => 1, 'items' => [], 'raw' => []];
        PaymentReceipt::create($base);
        $this->expectException(\Illuminate\Database\QueryException::class);
        PaymentReceipt::create($base);
    }
}
