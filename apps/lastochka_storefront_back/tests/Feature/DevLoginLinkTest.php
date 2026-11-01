<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\DevLoginService;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Dev-вход в веб-витрину по одноразовой подписанной ссылке (без SMS-кода).
 *
 * Сценарий: `php artisan dev:login-link <phone>` → GET по ссылке → сервер
 * выпускает Sanctum-токен и редиректит на витрину с токеном во фрагменте URL
 * (`#token=…`); фронт витрины на Nuxt сам кладёт его в свою куку `auth_token`.
 * Сервер куку НЕ ставит: кука с Domain=.mendev.ru от API и host-only кука
 * витрины были двумя разными куками с одним именем, и протухшая domain-кука
 * навсегда ломала обычный вход по SMS. Ссылка одноразовая, живёт TTL минут,
 * доступна только при DEV_LOGIN_ENABLED=true и когда хост APP_URL входит в
 * DEV_LOGIN_HOSTS.
 */
final class DevLoginLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'dev_login.enabled' => true,
            'dev_login.hosts' => parse_url(config('app.url'), PHP_URL_HOST),
            // Как на стенде: витрина на соседнем домене, абсолютный URL
            'dev_login.redirect' => 'https://front.example.test/',
        ]);

        $this->user = User::factory()->create();
    }

    private function link(): string
    {
        $code = Artisan::call('dev:login-link', ['phone' => $this->user->phone]);
        // output() очищает буфер: читаем один раз
        $output = trim(Artisan::output());
        $this->assertSame(Command::SUCCESS, $code, 'dev:login-link завершилась с ошибкой: '.$output);

        return $output;
    }

    /** Токен из фрагмента `#token=` в Location редиректа (parse_str раскодирует %7C обратно в `|`). */
    private function tokenFromRedirect(TestResponse $response): string
    {
        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_FRAGMENT), $params);

        $this->assertArrayHasKey('token', $params, 'В Location редиректа нет фрагмента #token=: '.$location);

        return (string) $params['token'];
    }

    /** Полный путь: команда → ссылка → редирект на витрину с валидным токеном во фрагменте URL. */
    public function test_link_redirects_to_storefront_with_token_in_url_fragment(): void
    {
        $url = $this->link();

        $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/dev-login/', $url);
        $this->assertStringContainsString('signature=', $url);

        $response = $this->get($url);

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');

        // Фрагмент не уходит на сервер: токена нет ни в nginx-логах, ни в SSR-запросе
        $this->assertStringStartsWith(config('dev_login.redirect').'#token=', $location);

        $plain = $this->tokenFromRedirect($response);
        $this->assertMatchesRegularExpression('/^\d+\|[A-Za-z0-9]{40,48}$/', $plain, 'Токен во фрагменте не похож на plainTextToken Sanctum');

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $this->user->id,
            'tokenable_type' => User::class,
            'name' => config('dev_login.token_name'),
            'token' => hash('sha256', explode('|', $plain, 2)[1]),
        ]);
    }

    /** Сервер куку auth_token не ставит: единственный её писатель теперь фронт витрины. */
    public function test_link_does_not_set_auth_token_cookie(): void
    {
        $response = $this->get($this->link());

        $response->assertRedirect();
        $this->assertNull($response->getCookie('auth_token', false), 'Редирект dev-входа не должен выставлять куку auth_token');
    }

    /** Токен из фрагмента реально авторизует запросы к API витрины. */
    public function test_issued_token_authorizes_api_requests(): void
    {
        $token = $this->tokenFromRedirect($this->get($this->link()));

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/profile')
            ->assertOk()
            ->assertJsonPath('data.phone', $this->user->phone);
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
        $this->user->createToken('auth_token');

        $this->get($this->link())->assertRedirect();
        $this->get($this->link())->assertRedirect();

        $names = $this->user->tokens()->pluck('name');
        $this->assertSame(1, $names->filter(fn ($n) => $n === config('dev_login.token_name'))->count());
        $this->assertTrue($names->contains('auth_token'));
    }

    /** Лишние query-параметры (nginx: try_files /index.php?admin=1&$query_string) подпись не ломают. */
    public function test_extra_query_params_do_not_break_signature(): void
    {
        $url = $this->link();

        $this->get($url.'&admin=1')->assertRedirect();
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

        config(['dev_login.hosts' => 'store.example.test']);

        $this->get($url)->assertStatus(404);
    }

    /** Витрина и API на разных поддоменах: абсолютный URL витрины берётся из конфига, фрагмент дописывается к нему. */
    public function test_absolute_redirect_is_configurable(): void
    {
        config(['dev_login.redirect' => 'https://other.example.test/shop']);

        $response = $this->get($this->link());

        $response->assertRedirect();
        $this->assertStringStartsWith('https://other.example.test/shop#token=', (string) $response->headers->get('Location'));
    }

    /** При выключенном флаге команда отказывает и ссылку не выпускает. */
    public function test_command_refuses_when_disabled(): void
    {
        config(['dev_login.enabled' => false]);

        $this->artisan('dev:login-link', ['phone' => $this->user->phone])->assertFailed();
        $this->assertSame(0, $this->user->tokens()->count());
    }

    /** Хост APP_URL не входит в DEV_LOGIN_HOSTS: команда отказывает даже при включённом флаге. */
    public function test_command_refuses_on_foreign_host(): void
    {
        config(['dev_login.hosts' => 'store.example.test, other.example.test']);

        $this->artisan('dev:login-link', ['phone' => $this->user->phone])->assertFailed();
    }

    /** Пустой DEV_LOGIN_HOSTS: команда отказывает (флаг без привязки к хосту не работает). */
    public function test_command_refuses_without_hosts(): void
    {
        config(['dev_login.hosts' => '']);

        $this->artisan('dev:login-link', ['phone' => $this->user->phone])->assertFailed();
    }

    /** Сравнение хостов регистронезависимое, пробелы вокруг запятых игнорируются. */
    public function test_hosts_match_is_case_insensitive(): void
    {
        $host = parse_url(config('app.url'), PHP_URL_HOST);
        config(['dev_login.hosts' => ' other.example.test , '.mb_strtoupper($host).' ']);

        $this->artisan('dev:login-link', ['phone' => $this->user->phone])->assertSuccessful();
    }

    /** Неизвестный телефон: ошибка, ничего не выпускается. */
    public function test_command_refuses_unknown_phone(): void
    {
        $this->artisan('dev:login-link', ['phone' => '+79990000001'])->assertFailed();
    }

    /** Удалённый (soft-deleted) клиент войти не может. */
    public function test_command_refuses_soft_deleted_user(): void
    {
        $this->user->delete();

        $this->artisan('dev:login-link', ['phone' => $this->user->phone])->assertFailed();
    }

    /** Soft-delete между выпуском ссылки и переходом по ней: вход отклоняется. */
    public function test_link_is_rejected_for_soft_deleted_user(): void
    {
        $url = $this->link();

        $this->user->delete();

        $this->get($url)->assertStatus(403);
    }

    /** Сервис: ссылка относительная к APP_URL и проверяется как relative (за nginx-прокси схема/хост могут отличаться). */
    public function test_service_builds_link_under_app_url(): void
    {
        $service = app(DevLoginService::class);

        $url = $service->createLink($this->user);

        $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/dev-login/', $url);
    }
}
