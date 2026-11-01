<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment;

use App\Services\Payment\YooKassaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Обрыв соединения с ЮKassa (прод 08.10.2026, GlitchTip 857: cURL 28 на capture)
 * повторяется один раз с тем же Idempotence-Key; ответы шлюза с ошибкой
 * не повторяются и не превращаются в исключение.
 */
final class YooKassaServiceConnectionRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    public function test_capture_retries_once_after_connection_error_with_same_idempotence_key(): void
    {
        Http::fake([
            'api.yookassa.ru/*' => Http::sequence()
                ->pushFailedConnection()
                ->push(['id' => 'pay_1', 'status' => 'succeeded'], 200),
        ]);

        $result = (new YooKassaService())->capture('pay_1', 100.0);

        $this->assertTrue($result->success);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->header('Idempotence-Key') === ['capture-pay_1']);
    }

    public function test_capture_throws_when_connection_error_persists(): void
    {
        Http::fake(['api.yookassa.ru/*' => Http::failedConnection()]);

        $this->expectException(ConnectionException::class);

        (new YooKassaService())->capture('pay_1', 100.0);
    }

    public function test_gateway_error_response_is_not_retried_and_not_thrown(): void
    {
        Http::fake([
            'api.yookassa.ru/*' => Http::response(['type' => 'error', 'description' => 'Payment already captured'], 400),
        ]);

        $result = (new YooKassaService())->capture('pay_1', 100.0);

        $this->assertFalse($result->success);
        Http::assertSentCount(1);
    }
}
