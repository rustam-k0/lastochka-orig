<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment;

use App\Models\IntegrationConfig;
use App\Models\Order;
use App\Services\Payment\ReceiptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReceiptBuilderFiscalDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_null_receipt_when_fiscal_driver_is_atol(): void
    {
        IntegrationConfig::create(['integration_type' => 'payment', 'driver' => 'yookassa', 'is_active' => true,
            'credentials' => ['yookassa' => ['send_receipt' => true]]]);
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => []]]);
        $order = Order::factory()->hasItems(1)->create(['total' => 100]);

        $this->assertNull(app(ReceiptBuilder::class)->forOrder($order));
    }

    public function test_builds_receipt_when_fiscal_driver_is_yookassa(): void
    {
        IntegrationConfig::create(['integration_type' => 'payment', 'driver' => 'yookassa', 'is_active' => true,
            'credentials' => ['yookassa' => ['send_receipt' => true]]]);
        $order = Order::factory()->hasItems(1)->create(['total' => 100]);

        $this->assertNotNull(app(ReceiptBuilder::class)->forOrder($order));
    }
}
