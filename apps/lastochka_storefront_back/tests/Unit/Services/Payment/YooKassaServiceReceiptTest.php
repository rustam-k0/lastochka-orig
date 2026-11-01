<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment;

use App\DTOs\Payment\ReceiptData;
use App\DTOs\Payment\ReceiptItemData;
use App\Models\Order;
use App\Services\Payment\YooKassaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Проверяет, что фискальный чек (receipt) реально уходит в тело запроса
 * POST /payments — без него онлайн-касса YooKassa возвращает
 * "Receipt is missing or illegal".
 */
final class YooKassaServiceReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_hold_includes_receipt_in_request_body(): void
    {
        Http::fake([
            'api.yookassa.ru/*' => Http::response([
                'id' => 'pay_test_1',
                'status' => 'pending',
                'confirmation' => ['confirmation_url' => 'https://yookassa.test/confirm'],
                'payment_method' => ['id' => 'pm_1'],
            ], 200),
        ]);

        $order = new Order();
        $order->id = 7;
        $order->total = 400;

        $receipt = new ReceiptData(
            items: [
                new ReceiptItemData('Товар A', 300.0, 4),
                new ReceiptItemData('Доставка', 100.0, 4, 1.0, 'service'),
            ],
            customerPhone: '79991234567',
        );

        $result = (new YooKassaService())->hold($order, null, $receipt);

        $this->assertTrue($result->success);

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return isset($body['receipt'])
                && $body['receipt']['customer']['phone'] === '79991234567'
                && count($body['receipt']['items']) === 2
                && $body['receipt']['items'][0]['vat_code'] === 4
                && $body['receipt']['items'][0]['amount']['value'] === '300.00'
                && $body['receipt']['items'][1]['payment_subject'] === 'service';
        });
    }

    public function test_hold_without_receipt_omits_receipt_key(): void
    {
        Http::fake([
            'api.yookassa.ru/*' => Http::response([
                'id' => 'pay_test_2',
                'status' => 'pending',
                'confirmation' => ['confirmation_url' => 'https://yookassa.test/confirm'],
                'payment_method' => ['id' => 'pm_1'],
            ], 200),
        ]);

        $order = new Order();
        $order->id = 8;
        $order->total = 500;

        (new YooKassaService())->hold($order);

        Http::assertSent(fn (Request $request): bool => ! isset($request->data()['receipt']));
    }
}
