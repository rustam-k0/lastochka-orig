<?php
declare(strict_types=1);
namespace App\Services\Payment\Fiscal;
use App\Services\IntegrationConfigService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class AtolClient
{
    public function register(string $operation, array $receipt, string $externalId): array
    {
        $creds = $this->creds();
        $body = [
            'external_id' => $externalId,
            'receipt' => $receipt,
            'timestamp' => now()->format('d.m.Y H:i:s'),
        ];

        // По протоколу АТОЛ (v4 и v5) callback_url передаётся в объекте service.
        // Top-level callback_url v4 молча игнорировал (чек добирался поллингом),
        // а v5 отклоняет весь чек: code 32 «#/callback_url … false schema».
        $callback = (string) config('services.atol.callback_url');
        if ($callback !== '') {
            $body['service'] = ['callback_url' => $callback];
        }

        $do = fn (string $token) => Http::timeout($this->timeout())
            ->post($this->url($creds, "/{$creds['group_code']}/{$operation}", $token), $body);

        $resp = $do($this->token($creds));
        if ($this->isAuthError($resp)) {
            $resp = $do($this->token($creds, force: true));
        }

        return $resp->json() ?? [];
    }

    public function report(string $uuid): array
    {
        $creds = $this->creds();
        $resp = Http::timeout($this->timeout())
            ->get($this->url($creds, "/{$creds['group_code']}/report/{$uuid}", $this->token($creds)));

        if ($this->isAuthError($resp)) {
            $resp = Http::timeout($this->timeout())
                ->get($this->url($creds, "/{$creds['group_code']}/report/{$uuid}", $this->token($creds, force: true)));
        }

        return $resp->json() ?? [];
    }

    private function token(array $creds, bool $force = false): string
    {
        // ffd_version в ключе: токен, полученный на v4, не переиспользуем на v5 и наоборот
        $key = "atol:token:{$creds['login']}:{$creds['mode']}:{$creds['ffd_version']}";
        if ($force) {
            Cache::forget($key);
        }

        $cached = Cache::get($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $resp = Http::timeout($this->timeout())->post($this->base($creds).'/getToken', [
            'login' => $creds['login'],
            'pass' => $creds['password'],
        ]);

        $token = (string) ($resp->json('token') ?? '');

        // Пустой/неудачный ответ getToken НЕ кешируем — иначе один транзиентный сбой
        // (сеть, протухшие креды) отключает всю фискализацию на 23 часа, даже после
        // того как причина устранена (GAP: было Cache::remember с '' внутри TTL).
        if ($token !== '') {
            Cache::put($key, $token, now()->addHours(23));
        }

        return $token;
    }

    private function isAuthError(\Illuminate\Http\Client\Response $resp): bool
    {
        return $resp->status() === 401
            || in_array($resp->json('error.code'), [5, 6, 12, 14], true); // группа auth-ошибок АТОЛ
    }

    private function base(array $creds): string
    {
        // ФФД 1.05 → APIv4 (base_url_*), ФФД 1.2 → APIv5 (base_url_*_v5)
        $suffix = $creds['ffd_version'] === '1.2' ? '_v5' : '';

        return $creds['mode'] === 'live'
            ? (string) config('services.atol.base_url_live'.$suffix)
            : (string) config('services.atol.base_url_test'.$suffix);
    }

    private function url(array $creds, string $path, string $token): string
    {
        return $this->base($creds).$path.'?token='.$token;
    }

    private function timeout(): int
    {
        return (int) config('services.atol.timeout', 15);
    }

    /** @return array{mode:string, ffd_version:string, login:string, password:string, group_code:string} */
    private function creds(): array
    {
        // RabbitMQ propagates credentials FLAT (IntegrationConfigEventTransformer::toEvent
        // sends only the active driver's sub-dict, no `atol` wrapper key). Accept both shapes.
        $all = IntegrationConfigService::getCredentials('fiscal');
        $c = $all['atol'] ?? $all;

        return [
            'mode' => $c['mode'] ?? 'test',
            'ffd_version' => $c['ffd_version'] ?? '1.05',
            'login' => $c['login'] ?? '',
            'password' => $c['password'] ?? '',
            'group_code' => $c['group_code'] ?? '',
        ];
    }
}
