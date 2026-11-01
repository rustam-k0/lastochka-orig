<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\IntegrationConfig;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class OneCConfigService
{
    protected static ?IntegrationConfig $cached = null;

    public static function get(): ?IntegrationConfig
    {
        if (static::$cached === null) {
            static::$cached = IntegrationConfig::where('integration_type', '1c')->first();
        }

        return static::$cached;
    }

    public static function clearCache(): void
    {
        static::$cached = null;
    }

    public static function login(): string
    {
        return static::getCredential('login', config('exchange1c.exchange_login', '1c_exchange'));
    }

    public static function password(): string
    {
        return static::getCredential('password', config('exchange1c.exchange_password', ''));
    }

    public static function getCredential(string $key, mixed $default = null): mixed
    {
        $config = static::get();

        if ($config === null) {
            return $default;
        }

        $credentials = $config->credentials;

        return $credentials[$key] ?? $default;
    }

    /**
     * Достать login/password из IntegrationConfig (только integration_type=1c)
     * и засинкать запись в users для mavsan Basic Auth.
     */
    public static function syncUserFromConfig(IntegrationConfig $config): void
    {
        if ($config->integration_type !== '1c') {
            return;
        }

        $credentials = $config->credentials;

        if (!is_array($credentials)) {
            return;
        }

        $login = $credentials['login'] ?? null;
        $password = $credentials['password'] ?? null;

        if (empty($login) || empty($password)) {
            return;
        }

        static::syncUserCredentials($login, $password);
    }

    /**
     * Обновить запись в таблице users для mavsan Basic Auth.
     * Вызывается из afterCreate/afterUpdate трансформера при получении новых credentials.
     */
    public static function syncUserCredentials(string $login, string $password): void
    {
        try {
            User::updateOrCreate(
                ['email' => $login],
                [
                    'name' => '1C Exchange User',
                    'password' => Hash::make($password),
                ]
            );

            Log::channel('1c-exchange')->info('1C Exchange user credentials updated', [
                'login' => $login,
            ]);
        } catch (\Throwable $e) {
            Log::channel('1c-exchange')->error('Failed to sync 1C user credentials', [
                'login' => $login,
                'error' => $e->getMessage(),
            ]);
        }

        static::clearCache();
    }
}
