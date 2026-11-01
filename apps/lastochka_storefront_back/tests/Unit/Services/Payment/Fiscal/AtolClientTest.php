<?php
declare(strict_types=1);
namespace Tests\Unit\Services\Payment\Fiscal;
use App\Models\IntegrationConfig;
use App\Services\Payment\Fiscal\AtolClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AtolClientTest extends TestCase
{
    use RefreshDatabase;

    private function config(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['mode' => 'test', 'login' => 'u', 'password' => 'p', 'group_code' => 'grp']]]);
    }

    public function test_register_authorizes_then_posts_sell(): void
    {
        $this->config();
        Http::fake([
            '*/getToken' => Http::response(['token' => 'TKN', 'error' => null], 200),
            '*/grp/sell*' => Http::response(['uuid' => 'U1', 'status' => 'wait', 'error' => null], 200),
        ]);

        $res = app(AtolClient::class)->register('sell', ['total' => 100], 'order-1-sale');

        $this->assertSame('U1', $res['uuid']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/grp/sell') && str_contains($r->url(), 'token=TKN'));
    }

    public function test_report_returns_done_payload(): void
    {
        $this->config();
        Http::fake([
            '*/getToken' => Http::response(['token' => 'TKN'], 200),
            '*/grp/report/U1*' => Http::response(['uuid' => 'U1', 'status' => 'done',
                'payload' => ['fn_number' => 'FN', 'fiscal_document_number' => 'FD',
                    'fiscal_document_attribute' => 'FP', 'ofd_receipt_url' => 'http://x']], 200),
        ]);

        $res = app(AtolClient::class)->report('U1');
        $this->assertSame('done', $res['status']);
        $this->assertSame('FN', $res['payload']['fn_number']);
    }

    /**
     * Finding #6 fix: `getToken` возвращающий пустой токен (сбой/ошибка) НЕ
     * должен кешироваться на 23 часа. Без фикса `Cache::remember` кешировал
     * бы '' на весь TTL, и второй вызов НЕ дошёл бы до getToken заново —
     * фискализация была бы отключена на сутки даже после того как креды
     * почини. Проверяем: пустой токен → второй register()/report() снова
     * бьёт getToken (не полагается на кеш).
     */
    public function test_empty_token_from_get_token_is_not_cached(): void
    {
        $this->config();
        Http::fake([
            '*/getToken' => Http::response(['token' => '', 'error' => ['code' => 1, 'text' => 'auth failed']], 200),
            '*/grp/sell*' => Http::response(['uuid' => 'U2', 'status' => 'wait', 'error' => null], 200),
        ]);

        app(AtolClient::class)->register('sell', ['total' => 100], 'order-2-sale');
        app(AtolClient::class)->register('sell', ['total' => 100], 'order-3-sale');

        // getToken должен быть вызван на КАЖДЫЙ register (пустой токен не закеширован).
        Http::assertSentCount(4); // 2 x getToken + 2 x sell
    }

    /**
     * Непустой токен, наоборот, ОБЯЗАН кешироваться: второй вызов НЕ должен
     * повторно бить getToken.
     */
    public function test_non_empty_token_is_cached_and_reused(): void
    {
        $this->config();
        Http::fake([
            '*/getToken' => Http::response(['token' => 'TKN'], 200),
            '*/grp/sell*' => Http::response(['uuid' => 'U3', 'status' => 'wait', 'error' => null], 200),
        ]);

        app(AtolClient::class)->register('sell', ['total' => 100], 'order-4-sale');
        app(AtolClient::class)->register('sell', ['total' => 100], 'order-5-sale');

        // getToken вызван только ОДИН раз (кеш переиспользован на второй register).
        Http::assertSentCount(3); // 1 x getToken + 2 x sell
    }

    /**
     * callback_url обязан уходить как service.callback_url (протокол АТОЛ v4/v5),
     * НЕ top-level: v4 такое поле молча игнорировал, v5 отклоняет чек целиком
     * (code 32 «#/callback_url All values fail against the false schema»).
     */
    public function test_register_sends_callback_url_under_service_key(): void
    {
        $this->config();
        config(['services.atol.callback_url' => 'https://shop.example/api/v1/webhooks/atol']);
        Http::fake([
            '*/getToken' => Http::response(['token' => 'TKN', 'error' => null], 200),
            '*/grp/sell*' => Http::response(['uuid' => 'U10', 'status' => 'wait', 'error' => null], 200),
        ]);

        app(AtolClient::class)->register('sell', ['total' => 100], 'order-10-sale');

        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), '/grp/sell')) {
                return false;
            }
            $body = $r->data();

            return ($body['service']['callback_url'] ?? null) === 'https://shop.example/api/v1/webhooks/atol'
                && ! array_key_exists('callback_url', $body);
        });
    }

    /**
     * ФФД 1.2 в настройках → все вызовы (getToken и регистрация чека) идут
     * на APIv5 (/possystem/v5), а не на захардкоженный v4.
     */
    public function test_ffd_12_hits_v5_base_url(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['mode' => 'test', 'ffd_version' => '1.2',
                'login' => 'u', 'password' => 'p', 'group_code' => 'grp']]]);
        Http::fake([
            '*/getToken' => Http::response(['token' => 'TKN', 'error' => null], 200),
            '*/grp/sell*' => Http::response(['uuid' => 'U7', 'status' => 'wait', 'error' => null], 200),
        ]);

        $res = app(AtolClient::class)->register('sell', ['total' => 100], 'order-7-sale');

        $this->assertSame('U7', $res['uuid']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'testonline.atol.ru/possystem/v5/getToken'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'testonline.atol.ru/possystem/v5/grp/sell'));
    }

    public function test_ffd_12_live_mode_hits_v5_online_url(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['mode' => 'live', 'ffd_version' => '1.2',
                'login' => 'u', 'password' => 'p', 'group_code' => 'grp']]]);
        Http::fake([
            '*/getToken' => Http::response(['token' => 'TKN', 'error' => null], 200),
            '*/grp/sell*' => Http::response(['uuid' => 'U8', 'status' => 'wait', 'error' => null], 200),
        ]);

        app(AtolClient::class)->register('sell', ['total' => 100], 'order-8-sale');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'https://online.atol.ru/possystem/v5/grp/sell')
            && ! str_contains($r->url(), 'testonline'));
    }

    /**
     * Без ffd_version в настройках (все существующие конфиги) — прежнее
     * поведение: APIv4. Обратная совместимость.
     */
    public function test_missing_ffd_version_defaults_to_v4_base_url(): void
    {
        $this->config();
        Http::fake([
            '*/getToken' => Http::response(['token' => 'TKN', 'error' => null], 200),
            '*/grp/sell*' => Http::response(['uuid' => 'U6', 'status' => 'wait', 'error' => null], 200),
        ]);

        app(AtolClient::class)->register('sell', ['total' => 100], 'order-6-sale');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'testonline.atol.ru/possystem/v4/grp/sell'));
    }

    /**
     * ATOL flat-credentials bug: on real dev, RabbitMQ propagates credentials flat
     * (no `atol` wrapper key — see AtolReceiptBuilderTest for the full explanation).
     * `creds()` must fall back to the flat dict itself when `['atol']` is absent,
     * otherwise `group_code`/`login`/`password` are all empty and every ATOL call
     * goes out unauthenticated.
     */
    public function test_register_reads_flat_credentials_from_rabbitmq_propagation(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['mode' => 'test', 'login' => 'u', 'password' => 'p', 'group_code' => 'grp']]);
        Http::fake([
            '*/getToken' => Http::response(['token' => 'TKN', 'error' => null], 200),
            '*/grp/sell*' => Http::response(['uuid' => 'U9', 'status' => 'wait', 'error' => null], 200),
        ]);

        $res = app(AtolClient::class)->register('sell', ['total' => 100], 'order-9-sale');

        $this->assertSame('U9', $res['uuid']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/grp/sell') && str_contains($r->url(), 'token=TKN'));
    }
}
