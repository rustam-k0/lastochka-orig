<?php

namespace Tests\Unit;

use App\Services\StorefrontPaymentClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StorefrontPaymentClientTest extends TestCase
{
    public function test_successful_settle(): void
    {
        config(['services.storefront.url' => 'http://storefront.test', 'services.storefront.internal_token' => 'tok']);
        Http::fake(['http://storefront.test/api/v1/internal/orders/42/settle-payment' =>
            Http::response(['status' => 'ok', 'payment_status' => 'paid'], 200)]);

        $result = app(StorefrontPaymentClient::class)->settleOrderPayment(42, 6000.0);

        $this->assertTrue($result['ok']);
        $this->assertSame(200, $result['status']);
        Http::assertSent(fn ($request) => $request->hasHeader('X-Internal-Token', 'tok')
            && $request['amount'] === 6000.0);
    }

    public function test_declined_settle(): void
    {
        config(['services.storefront.url' => 'http://storefront.test', 'services.storefront.internal_token' => 'tok']);
        Http::fake(['*' => Http::response(['status' => 'failed', 'message' => 'Недостаточно средств на карте', 'surcharge_amount' => 1000], 402)]);

        $result = app(StorefrontPaymentClient::class)->settleOrderPayment(42, 6000.0);

        $this->assertFalse($result['ok']);
        $this->assertSame(402, $result['status']);
        $this->assertSame('Недостаточно средств на карте', $result['body']['message']);
    }
}
