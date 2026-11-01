<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DevLoginService;
use Illuminate\Console\Command;

class DevLoginLink extends Command
{
    protected $signature = 'dev:login-link {email : Email пользователя админки}';

    protected $description = 'Одноразовая ссылка для входа в админку без пароля и 2FA (только dev: DEV_LOGIN_ENABLED=true и хост APP_URL в DEV_LOGIN_HOSTS)';

    public function handle(DevLoginService $service): int
    {
        if (!$service->isAvailable()) {
            $this->error('Dev-вход выключен: нужен DEV_LOGIN_ENABLED=true и хост APP_URL в DEV_LOGIN_HOSTS.');
            return self::FAILURE;
        }

        $email = $this->argument('email');
        $user = User::whereEmail($email)->first();

        if (!$user) {
            $this->error("Пользователь {$email} не найден.");
            return self::FAILURE;
        }

        // Печатаем только URL: вывод удобно подхватывать скриптами
        $this->line($service->createLink($user));

        return self::SUCCESS;
    }
}
