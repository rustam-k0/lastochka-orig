<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Testers\AtolTester;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AtolTesterTest extends TestCase
{
    private function validCredentials(array $overrides = []): array
    {
        return array_merge([
            'mode' => 'test',
            'login' => 'api_login',
            'password' => 'api_password',
            'group_code' => 'group_code_1',
        ], $overrides);
    }

    public function test_success_returns_true_with_token(): void
    {
        Http::fake([
            '*/getToken' => Http::response(['token' => 'some-token-value', 'error' => null], 200),
        ]);

        $result = (new AtolTester())->test($this->validCredentials());

        $this->assertTrue($result->success);
        $this->assertSame('Авторизация успешна', $result->message);
    }

    public function test_invalid_credentials_returns_atol_error_text(): void
    {
        Http::fake([
            '*/getToken' => Http::response([
                'token' => null,
                'error' => ['code' => 1, 'text' => 'Неверный логин или пароль'],
            ], 200),
        ]);

        $result = (new AtolTester())->test($this->validCredentials());

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Неверный логин или пароль', (string) $result->message);
    }

    public function test_empty_token_without_error_text_returns_generic_failure(): void
    {
        Http::fake([
            '*/getToken' => Http::response(['token' => null, 'error' => null], 200),
        ]);

        $result = (new AtolTester())->test($this->validCredentials());

        $this->assertFalse($result->success);
        $this->assertNotEmpty($result->message);
    }

    public function test_missing_login_fails_fast_without_http_call(): void
    {
        Http::fake();

        $result = (new AtolTester())->test($this->validCredentials(['login' => '']));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('логин', mb_strtolower((string) $result->message));
        Http::assertNothingSent();
    }

    public function test_missing_password_fails_fast_without_http_call(): void
    {
        Http::fake();

        $result = (new AtolTester())->test($this->validCredentials(['password' => '']));

        $this->assertFalse($result->success);
        Http::assertNothingSent();
    }

    public function test_missing_group_code_fails_fast_without_http_call(): void
    {
        Http::fake();

        $result = (new AtolTester())->test($this->validCredentials(['group_code' => '']));

        $this->assertFalse($result->success);
        Http::assertNothingSent();
    }

    public function test_missing_all_credentials_fails_fast(): void
    {
        $result = (new AtolTester())->test([]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('логин', mb_strtolower((string) $result->message));
    }

    public function test_connection_exception_is_caught_as_failure(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('timed out');
        });

        $result = (new AtolTester())->test($this->validCredentials());

        $this->assertFalse($result->success);
        $this->assertNotEmpty($result->message);
    }

    public function test_generic_throwable_is_caught_as_failure(): void
    {
        Http::fake(function () {
            throw new \RuntimeException('boom');
        });

        $result = (new AtolTester())->test($this->validCredentials());

        $this->assertFalse($result->success);
        $this->assertNotEmpty($result->message);
    }

    public function test_test_mode_hits_testonline_base_url(): void
    {
        Http::fake([
            '*/getToken' => Http::response(['token' => 'tok', 'error' => null], 200),
        ]);

        (new AtolTester())->test($this->validCredentials(['mode' => 'test']));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'https://testonline.atol.ru/possystem/v4/getToken');
        });
    }

    public function test_live_mode_hits_online_base_url(): void
    {
        Http::fake([
            '*/getToken' => Http::response(['token' => 'tok', 'error' => null], 200),
        ]);

        (new AtolTester())->test($this->validCredentials(['mode' => 'live']));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'https://online.atol.ru/possystem/v4/getToken')
                && ! str_contains($request->url(), 'testonline');
        });
    }

    public function test_ffd_12_test_mode_hits_v5_testonline_url(): void
    {
        Http::fake([
            '*/getToken' => Http::response(['token' => 'tok', 'error' => null], 200),
        ]);

        (new AtolTester())->test($this->validCredentials(['ffd_version' => '1.2']));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'https://testonline.atol.ru/possystem/v5/getToken');
        });
    }

    public function test_ffd_12_live_mode_hits_v5_online_url(): void
    {
        Http::fake([
            '*/getToken' => Http::response(['token' => 'tok', 'error' => null], 200),
        ]);

        (new AtolTester())->test($this->validCredentials(['mode' => 'live', 'ffd_version' => '1.2']));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'https://online.atol.ru/possystem/v5/getToken')
                && ! str_contains($request->url(), 'testonline');
        });
    }

    public function test_ffd_105_explicitly_hits_v4_url(): void
    {
        Http::fake([
            '*/getToken' => Http::response(['token' => 'tok', 'error' => null], 200),
        ]);

        (new AtolTester())->test($this->validCredentials(['ffd_version' => '1.05']));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'https://testonline.atol.ru/possystem/v4/getToken');
        });
    }

    public function test_sends_login_and_pass_in_request_body(): void
    {
        Http::fake([
            '*/getToken' => Http::response(['token' => 'tok', 'error' => null], 200),
        ]);

        (new AtolTester())->test($this->validCredentials(['login' => 'my_login', 'password' => 'my_pass']));

        Http::assertSent(function ($request) {
            return $request['login'] === 'my_login' && $request['pass'] === 'my_pass';
        });
    }
}
