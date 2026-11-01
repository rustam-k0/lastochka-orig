<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Dev-вход в админку по одноразовой подписанной ссылке (без пароля и 2FA).
 *
 * Два независимых гейта: DEV_LOGIN_ENABLED=true и хост APP_URL в DEV_LOGIN_HOSTS
 * (push в master деплоит и dev, и прод; APP_ENV на стендах ненадёжен).
 * Nonce живёт в кэше ровно TTL и гасится при первом переходе.
 */
class DevLoginService
{
    public const ROUTE_NAME = 'dev-login';

    private const CACHE_PREFIX = 'dev_login:';

    /** Флаг включён: по нему регистрируется роут (проверка хоста делается на рантайме). */
    public function isEnabled(): bool
    {
        return (bool) config('dev_login.enabled');
    }

    /** Оба гейта: флаг включён и хост APP_URL входит в DEV_LOGIN_HOSTS. */
    public function isAvailable(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' && in_array(mb_strtolower($host), $this->allowedHosts(), true);
    }

    /** @return list<string> */
    public function allowedHosts(): array
    {
        $hosts = array_map('trim', explode(',', (string) config('dev_login.hosts')));

        return array_values(array_filter(array_map('mb_strtolower', $hosts)));
    }

    /**
     * Абсолютная одноразовая ссылка под APP_URL.
     *
     * Подпись собственная: HMAC-SHA256 по `nonce|expires` на APP_KEY, проверяется
     * в контроллере (см. hasValidSignature). Штатный `signed` middleware не подходит:
     * nginx стендов подмешивает в query string служебные параметры
     * (`try_files ... /index.php?admin=1&$query_string`), а за прокси схема и хост
     * могут отличаться от APP_URL, и подпись Laravel по URL не сходится.
     */
    public function createLink(User $user): string
    {
        $nonce = Str::random(48);
        $ttl = now()->addMinutes($this->ttlMinutes());
        $expires = $ttl->getTimestamp();

        Cache::put(self::CACHE_PREFIX . $nonce, $user->id, $ttl);

        $path = route(self::ROUTE_NAME, ['nonce' => $nonce], false);

        return rtrim(config('app.url'), '/') . $path
            . '?expires=' . $expires . '&signature=' . $this->signature($nonce, $expires);
    }

    /** Подпись валидна и срок ссылки не истёк (лишние query-параметры игнорируются). */
    public function hasValidSignature(string $nonce, mixed $expires, mixed $signature): bool
    {
        if (!is_string($expires) && !is_int($expires)) {
            return false;
        }

        $expires = (string) $expires;

        if ($expires === '' || !ctype_digit($expires) || !is_string($signature) || $signature === '') {
            return false;
        }

        if ((int) $expires < now()->getTimestamp()) {
            return false;
        }

        return hash_equals($this->signature($nonce, (int) $expires), $signature);
    }

    public function signature(string $nonce, int $expires): string
    {
        return hash_hmac('sha256', $nonce . '|' . $expires, (string) config('app.key'));
    }

    /** Гасит nonce и возвращает пользователя; null, если ссылка уже использована или неизвестна. */
    public function consume(string $nonce): ?User
    {
        $userId = Cache::pull(self::CACHE_PREFIX . $nonce);

        if ($userId === null) {
            return null;
        }

        return User::find($userId);
    }

    /** Выпускает свежий токен, удаляя предыдущие dev-login токены пользователя (Sanctum-токены бессрочные). */
    public function issueToken(User $user): string
    {
        $name = config('dev_login.token_name');

        $user->tokens()->where('name', $name)->delete();

        return $user->createToken($name)->plainTextToken;
    }

    public function ttlMinutes(): int
    {
        return max(1, (int) config('dev_login.ttl_minutes'));
    }
}
