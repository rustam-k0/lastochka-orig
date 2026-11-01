<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment;

use App\Models\Order;
use App\Services\Payment\YooKassaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `confirmation.return_url` — адрес, на который ЮKassa вернёт клиента после
 * подтверждения платежа. Он собирается только в redirect-флоу (новая карта):
 * при оплате сохранённой картой блока confirmation в запросе нет вовсе.
 */
final class YooKassaServiceReturnUrlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://api.example.ru',
            'services.yookassa.return_url' => 'https://store.example.ru/payment/success',
            'services.yookassa.allowed_return_hosts' => '',
            'services.yookassa.allowed_return_schemes' => '',
        ]);

        Http::fake([
            'api.yookassa.ru/*' => Http::response([
                'id' => 'pay_1',
                'status' => 'pending',
                'confirmation' => ['confirmation_url' => 'https://yoomoney.test/checkout/pay_1'],
            ], 200),
        ]);
    }

    public function test_passed_return_url_goes_to_yookassa(): void
    {
        (new YooKassaService)->hold($this->order(), returnUrl: 'https://store.example.ru/orders/11');

        $this->assertSentReturnUrl('https://store.example.ru/orders/11');
    }

    public function test_default_return_url_is_used_when_client_passed_nothing(): void
    {
        (new YooKassaService)->hold($this->order());

        $this->assertSentReturnUrl('https://store.example.ru/payment/success');
    }

    public function test_foreign_return_url_falls_back_to_default(): void
    {
        // Вторая линия защиты: даже если адрес минует валидацию запроса,
        // чужой домен в ЮKassa не уедет.
        (new YooKassaService)->hold($this->order(), returnUrl: 'https://evil.tld/pay');

        $this->assertSentReturnUrl('https://store.example.ru/payment/success');
    }

    public function test_relative_default_from_config_is_absolutized(): void
    {
        config(['services.yookassa.return_url' => '/']);

        (new YooKassaService)->hold($this->order());

        $this->assertSentReturnUrl('https://api.example.ru/');
    }

    public function test_saved_card_payment_has_no_confirmation_block(): void
    {
        (new YooKassaService)->hold($this->order(), 'pm_1', returnUrl: 'https://store.example.ru/orders/11');

        Http::assertSent(function ($request): bool {
            $this->assertArrayNotHasKey('confirmation', $request->data());

            return true;
        });
    }

    private function order(): Order
    {
        $order = new Order;
        $order->id = 11;
        $order->total = 400;

        return $order;
    }

    private function assertSentReturnUrl(string $expected): void
    {
        Http::assertSent(function ($request) use ($expected): bool {
            $this->assertSame($expected, $request->data()['confirmation']['return_url'] ?? null);

            return true;
        });
    }
}
