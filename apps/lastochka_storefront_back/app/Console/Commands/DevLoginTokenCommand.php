<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DevLoginService;
use Illuminate\Console\Command;

/**
 * Dev-вход на клиентскую часть без SMS-кода: печатает Sanctum-токен пользователя.
 *
 * Два независимых гейта: DEV_LOGIN_ENABLED=true и хост APP_URL в DEV_LOGIN_HOSTS
 * (APP_ENV на стендах ненадёжен). Старые dev-login токены пользователя удаляются.
 */
class DevLoginTokenCommand extends Command
{
    protected $signature = 'dev:login-token {phone : Телефон клиента в том виде, как он хранится в users.phone (например +79000000000)}';

    protected $description = 'Sanctum-токен клиента для входа в API без SMS-кода (только dev: DEV_LOGIN_ENABLED=true и хост APP_URL в DEV_LOGIN_HOSTS)';

    public function handle(DevLoginService $service): int
    {
        if (! $service->isAvailable()) {
            $this->error('Dev-вход выключен: нужен DEV_LOGIN_ENABLED=true и хост APP_URL в DEV_LOGIN_HOSTS.');

            return self::FAILURE;
        }

        $phone = (string) $this->argument('phone');
        $user = User::query()->where('phone', $phone)->first();

        if (! $user) {
            $this->error("Клиент с телефоном {$phone} не найден.");

            return self::FAILURE;
        }

        // Печатаем только токен: вывод удобно подхватывать скриптами
        $this->line($service->issueToken($user));

        return self::SUCCESS;
    }
}
