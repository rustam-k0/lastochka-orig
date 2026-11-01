<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DevLoginService;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Dev-вход в админку по одноразовой подписанной ссылке (без пароля и 2FA).
 *
 * Сценарий: `php artisan dev:login-link <email>` → GET по ссылке → сервер
 * выпускает Sanctum-токен и кладёт его в JS-куку `admin_token`, которую
 * читает admin_front. Ссылка одноразовая, живёт TTL минут, доступна только
 * при DEV_LOGIN_ENABLED=true и когда хост APP_URL входит в DEV_LOGIN_HOSTS.
 */
class DevLoginTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'dev_login.enabled' => true,
            'dev_login.hosts'   => parse_url(config('app.url'), PHP_URL_HOST),
        ]);

        $this->user = User::create([
            'email'    => 'dev-login-' . Str::lower(Str::random(8)) . '@example.test',
            'password' => bcrypt(Str::random(16)),
        ]);
    }

    private function link(): string
    {
        $code = Artisan::call('dev:login-link', ['email' => $this->user->email]);
        // output() очищает буфер: читаем один раз
        $output = trim(Artisan::output());
        $this->assertSame(Command::SUCCESS, $code, 'dev:login-link завершилась с ошибкой: ' . $output);

        return $output;
    }

    /** Полный путь: команда → ссылка → редирект с кукой admin_token и валидным токеном. */
    public function test_link_logs_in_and_sets_admin_token_cookie(): void
    {
        $url = $this->link();

        $this->assertStringStartsWith(rtrim(config('app.url'), '/') . '/action/dev-login/', $url);
        $this->assertStringContainsString('signature=', $url);

        $response = $this->get($url);

        $response->assertRedirect(config('dev_login.redirect'));
        $response->assertCookieNotExpired('admin_token');

        $cookie = $response->getCookie('admin_token', false);
        $this->assertNotNull($cookie, 'Кука admin_token не выставлена');

        // Кука должна быть в открытом виде (фронт читает её через js-cookie),
        // не HttpOnly и с SameSite=Strict как у самого фронта.
        $this->assertFalse($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());

        $plain = $cookie->getValue();
        $this->assertMatchesRegularExpression('/^\d+\|[A-Za-z0-9]{40}$/', $plain, 'Значение куки не похоже на plainTextToken Sanctum');

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id'   => $this->user->id,
            'tokenable_type' => User::class,
            'name'           => config('dev_login.token_name'),
            'token'          => hash('sha256', explode('|', $plain, 2)[1]),
        ]);
    }

    /** Токен из куки реально авторизует запросы к GraphQL админки. */
    public function test_issued_token_authorizes_graphql_requests(): void
    {
        $response = $this->get($this->link());
        $token = $response->getCookie('admin_token', false)->getValue();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/graphql-admin', ['query' => '{ me { id email } }'])
            ->assertOk()
            ->assertJsonPath('data.me.email', $this->user->email);
    }

    /** Ссылка одноразовая: второй переход по ней отклоняется. */
    public function test_link_is_single_use(): void
    {
        $url = $this->link();

        $this->get($url)->assertRedirect();
        $this->get($url)->assertStatus(403);
    }

    /** Повторный выпуск удаляет предыдущие dev-login токены, но не трогает остальные. */
    public function test_reissue_replaces_previous_dev_login_tokens_only(): void
    {
        $this->user->createToken('Регулярный вход');

        $this->get($this->link())->assertRedirect();
        $this->get($this->link())->assertRedirect();

        $tokens = $this->user->tokens()->pluck('name');
        $this->assertSame(1, $tokens->filter(fn ($n) => $n === config('dev_login.token_name'))->count());
        $this->assertTrue($tokens->contains('Регулярный вход'));
    }

    /** Лишние query-параметры (nginx: try_files /index.php?admin=1&$query_string) подпись не ломают. */
    public function test_extra_query_params_do_not_break_signature(): void
    {
        $url = $this->link();

        $this->get($url . '&admin=1')->assertRedirect();
    }

    /** Подпись, посчитанная под другой nonce, отклоняется и чужой nonce не гасится. */
    public function test_signature_is_bound_to_nonce(): void
    {
        $service = app(DevLoginService::class);
        $victim = $this->link();
        $attacker = $this->link();

        $victimNonce = explode('?', basename($victim), 2)[0];
        [, $attackerQuery] = explode('?', $attacker, 2);

        $this->get(rtrim(config('app.url'), '/') . '/action/dev-login/' . $victimNonce . '?' . $attackerQuery)
            ->assertStatus(403);

        // Жертвенная ссылка по-прежнему рабочая
        $this->get($victim)->assertRedirect();
        $this->assertNotNull($service);
    }

    /** Подделанная подпись отклоняется. */
    public function test_tampered_signature_is_rejected(): void
    {
        $url = $this->link();

        $this->get(preg_replace('/signature=\w{4}/', 'signature=dead', $url))->assertStatus(403);
    }

    /** Просроченная ссылка отклоняется. */
    public function test_expired_link_is_rejected(): void
    {
        $url = $this->link();

        $this->travel(config('dev_login.ttl_minutes') + 1)->minutes();

        $this->get($url)->assertStatus(403);
    }

    /** Nonce, которого нет в кэше (например, кэш сброшен), не проходит даже с валидной подписью. */
    public function test_unknown_nonce_is_rejected(): void
    {
        $url = $this->link();

        Cache::flush();

        $this->get($url)->assertStatus(403);
    }

    /** При выключенном флаге команда отказывает и ссылку не выпускает. */
    public function test_command_refuses_when_disabled(): void
    {
        config(['dev_login.enabled' => false]);

        $this->artisan('dev:login-link', ['email' => $this->user->email])
            ->assertFailed();
    }

    /** Хост APP_URL не входит в DEV_LOGIN_HOSTS: команда отказывает даже при включённом флаге. */
    public function test_command_refuses_on_foreign_host(): void
    {
        config(['dev_login.hosts' => 'admin.example.test, other.example.test']);

        $this->artisan('dev:login-link', ['email' => $this->user->email])
            ->assertFailed();
    }

    /** Пустой DEV_LOGIN_HOSTS: команда отказывает (флаг без привязки к хосту не работает). */
    public function test_command_refuses_without_hosts(): void
    {
        config(['dev_login.hosts' => '']);

        $this->artisan('dev:login-link', ['email' => $this->user->email])
            ->assertFailed();
    }

    /** Сравнение хостов регистронезависимое, пробелы вокруг запятых игнорируются. */
    public function test_hosts_match_is_case_insensitive(): void
    {
        $host = parse_url(config('app.url'), PHP_URL_HOST);
        config(['dev_login.hosts' => ' other.example.test , ' . mb_strtoupper($host) . ' ']);

        $this->artisan('dev:login-link', ['email' => $this->user->email])
            ->assertSuccessful();
    }

    /** Неизвестный email: ошибка, ничего не выпускается. */
    public function test_command_refuses_unknown_email(): void
    {
        $this->artisan('dev:login-link', ['email' => 'nobody-' . Str::random(6) . '@example.test'])
            ->assertFailed();
    }

    /** Роут отвечает 404, если флаг выключен на момент запроса (config:cache без DEV_LOGIN_ENABLED). */
    public function test_route_returns_404_when_disabled_at_runtime(): void
    {
        $url = $this->link();

        config(['dev_login.enabled' => false]);

        $this->get($url)->assertStatus(404);
    }

    /** Роут отвечает 404 на чужом хосте даже с валидной ссылкой (dev-.env скопирован на другой стенд). */
    public function test_route_returns_404_on_foreign_host(): void
    {
        $url = $this->link();

        config(['dev_login.hosts' => 'admin.example.test']);

        $this->get($url)->assertStatus(404);
    }

    /** Фронт и API на разных поддоменах: домен куки и абсолютный редирект берутся из конфига. */
    public function test_cookie_domain_and_absolute_redirect_are_configurable(): void
    {
        config([
            'dev_login.cookie_domain' => '.example.test',
            'dev_login.redirect'      => 'https://admin.example.test/',
        ]);

        $response = $this->get($this->link());

        $response->assertRedirect('https://admin.example.test/');
        $this->assertSame('.example.test', $response->getCookie('admin_token', false)->getDomain());
    }

    /** Сервис: ссылка относительная к APP_URL и проверяется как relative (за nginx-прокси схема/хост могут отличаться). */
    public function test_service_builds_link_under_app_url(): void
    {
        $service = app(DevLoginService::class);

        $url = $service->createLink($this->user);

        $this->assertStringStartsWith(rtrim(config('app.url'), '/') . '/action/dev-login/', $url);
    }
}
