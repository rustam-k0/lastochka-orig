<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment;

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Services\Payment\YooKassaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ошибка шлюза уходит сборщику и покупателю как есть: delivery_back
 * подставляет её в текст модалки «Увы! Платёж не прошёл», мобилка —
 * в сообщение об отказе. ЮKassa отвечает по-английски («The payment
 * amount is smaller than the minimum payment limit»), и сборщик в
 * магазине читал это вместе с советом пополнить карту, хотя денег
 * хватало. Английский текст наружу не выходит.
 */
final class YooKassaServiceErrorMessageTest extends TestCase
{
    use RefreshDatabase;

    private function order(): Order
    {
        return Order::factory()->for(User::factory())->for(Store::factory())->create();
    }

    public function test_charge_below_minimum_limit_returns_russian_message(): void
    {
        Http::fake([
            'api.yookassa.ru/*' => Http::response([
                'type' => 'error',
                'id' => 'err_1',
                'code' => 'invalid_request',
                'description' => 'The payment amount is smaller than the minimum payment limit',
                'parameter' => 'amount',
            ], 400),
        ]);

        $result = (new YooKassaService)->charge($this->order(), 0.09, 'pm_test');

        $this->assertFalse($result->success);
        $this->assertSame(
            'Сумма меньше минимального платежа, который принимает банк',
            $result->errorMessage,
        );
    }

    public function test_capture_below_minimum_limit_returns_russian_message(): void
    {
        Http::fake([
            'api.yookassa.ru/*' => Http::response([
                'type' => 'error',
                'description' => 'The payment amount is smaller than the minimum payment limit',
            ], 400),
        ]);

        $result = (new YooKassaService)->capture('pay_1', 0.09);

        $this->assertFalse($result->success);
        $this->assertSame(
            'Сумма меньше минимального платежа, который принимает банк',
            $result->errorMessage,
        );
    }

    public function test_unknown_gateway_error_falls_back_to_russian_default(): void
    {
        Http::fake([
            'api.yookassa.ru/*' => Http::response([
                'type' => 'error',
                'description' => 'Some brand new gateway failure',
            ], 400),
        ]);

        $result = (new YooKassaService)->charge($this->order(), 100.0, 'pm_test');

        $this->assertFalse($result->success);
        $this->assertSame('Ошибка досписания', $result->errorMessage);
    }
}
