<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DevLoginService;
use Illuminate\Console\Command;

/**
 * Dev-вход в веб-витрину без SMS-кода: печатает одноразовую подписанную ссылку.
 *
 * Переход по ссылке выпускает Sanctum-токен и редиректит на витрину с ним во
 * фрагменте URL (`#token=…`); куку `auth_token` кладёт сама витрина. Два
 * независимых гейта: DEV_LOGIN_ENABLED=true и хост APP_URL в DEV_LOGIN_HOSTS
 * (APP_ENV на стендах ненадёжен).
 */
final class DevLoginLinkCommand extends Command
{
    protected $signature = 'dev:login-link {phone : Телефон клиента в том виде, как он хранится в users.phone (например +79000000000)}';

    protected $description = 'Одноразовая ссылка для входа в веб-витрину без SMS-кода (только dev: DEV_LOGIN_ENABLED=true и хост APP_URL в DEV_LOGIN_HOSTS)';

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

        // Печатаем только URL: вывод удобно подхватывать скриптами
        $this->line($service->createLink($user));

        return self::SUCCESS;
    }
}
