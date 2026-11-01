<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Dev-вход на клиентскую часть (API мобильного приложения) без SMS-кода:
 * `php artisan dev:login-token <phone>` печатает Sanctum-токен пользователя.
 * Доступно только при DEV_LOGIN_ENABLED=true и когда хост APP_URL входит в DEV_LOGIN_HOSTS.
 */
final class DevLoginTokenTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'dev_login.enabled' => true,
            'dev_login.hosts' => parse_url(config('app.url'), PHP_URL_HOST),
        ]);

        $this->user = User::factory()->create();
    }

    private function token(): string
    {
        $code = Artisan::call('dev:login-token', ['phone' => $this->user->phone]);
        // output() очищает буфер: читаем один раз
        $output = trim(Artisan::output());
        $this->assertSame(Command::SUCCESS, $code, 'dev:login-token завершилась с ошибкой: '.$output);

        return $output;
    }

    public function test_prints_token_that_authorizes_api_requests(): void
    {
        $token = $this->token();

        $this->assertMatchesRegularExpression('/^\d+\|[A-Za-z0-9]{40,48}$/', $token);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/profile')
            ->assertOk()
            ->assertJsonPath('data.phone', $this->user->phone);
    }

    public function test_reissue_replaces_previous_dev_login_tokens_only(): void
    {
        $this->user->createToken('auth_token');

        $this->token();
        $this->token();

        $names = $this->user->tokens()->pluck('name');
        $this->assertSame(1, $names->filter(fn ($n) => $n === config('dev_login.token_name'))->count());
        $this->assertTrue($names->contains('auth_token'));
    }

    public function test_refuses_when_disabled(): void
    {
        config(['dev_login.enabled' => false]);

        $this->artisan('dev:login-token', ['phone' => $this->user->phone])->assertFailed();
        $this->assertSame(0, $this->user->tokens()->count());
    }

    public function test_refuses_on_foreign_host(): void
    {
        config(['dev_login.hosts' => 'store.example.test']);

        $this->artisan('dev:login-token', ['phone' => $this->user->phone])->assertFailed();
        $this->assertSame(0, $this->user->tokens()->count());
    }

    public function test_refuses_without_hosts(): void
    {
        config(['dev_login.hosts' => '']);

        $this->artisan('dev:login-token', ['phone' => $this->user->phone])->assertFailed();
    }

    public function test_hosts_match_is_case_insensitive(): void
    {
        $host = parse_url(config('app.url'), PHP_URL_HOST);
        config(['dev_login.hosts' => ' other.example.test , '.mb_strtoupper($host).' ']);

        $this->artisan('dev:login-token', ['phone' => $this->user->phone])->assertSuccessful();
    }

    public function test_refuses_unknown_phone(): void
    {
        $this->artisan('dev:login-token', ['phone' => '+79990000001'])->assertFailed();
    }

    public function test_refuses_soft_deleted_user(): void
    {
        $this->user->delete();

        $this->artisan('dev:login-token', ['phone' => $this->user->phone])->assertFailed();
    }
}
