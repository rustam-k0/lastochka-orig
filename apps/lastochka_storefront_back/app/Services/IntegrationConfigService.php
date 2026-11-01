<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\IntegrationConfig;

class IntegrationConfigService
{
    public static function get(string $type): ?IntegrationConfig
    {
        return IntegrationConfig::where('integration_type', $type)
            ->where('is_active', true)
            ->first();
    }

    public static function getDriver(string $type): ?string
    {
        return static::get($type)?->driver;
    }

    public static function getCredentials(string $type): array
    {
        return static::get($type)?->credentials ?? [];
    }

    /**
     * Креды активного драйвера интеграции.
     *
     * Настройки хранятся либо плоско, либо вложенным блоком на драйвер
     * (`['yookassa' => [...]]`) — этот метод скрывает обе раскладки.
     *
     * @param  string|null  $fallbackDriver  Драйвер, если интеграция не настроена.
     * @return array<string, mixed>
     */
    public static function getDriverCredentials(string $type, ?string $fallbackDriver = null): array
    {
        $config = static::get($type);
        $credentials = $config?->credentials ?? [];
        $driver = $config?->driver ?? $fallbackDriver;

        if ($driver !== null && is_array($credentials[$driver] ?? null)) {
            return $credentials[$driver];
        }

        return $credentials;
    }
}
