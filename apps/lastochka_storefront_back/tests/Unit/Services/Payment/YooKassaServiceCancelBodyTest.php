<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment;

use App\Services\Payment\YooKassaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * POST /payments/{id}/cancel (и любой POST с пустым телом) обязан уходить
 * с пустым JSON-объектом {}, а НЕ массивом []. YooKassa на тело [] отвечает
 * 400 "Unable to parse the response body, check the validity of JSON" —
 * из-за чего холды (1 ₽ привязки карты, отмена заказов) реально не снимались.
 */
final class YooKassaServiceCancelBodyTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancel_sends_empty_json_object_not_array(): void
    {
        Http::fake([
            'api.yookassa.ru/*' => Http::response([
                'id' => 'pay_cancel_1',
                'status' => 'canceled',
            ], 200),
        ]);

        $result = (new YooKassaService())->cancel('pay_cancel_1');

        $this->assertTrue($result->success);

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/payments/pay_cancel_1/cancel')
                && $request->body() === '{}';
        });
    }
}
