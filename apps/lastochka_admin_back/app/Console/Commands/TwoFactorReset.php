<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class TwoFactorReset extends Command
{
    protected $signature = 'two-factor:reset {email}';

    protected $description = 'Сбросить двухфакторную аутентификацию пользователя по email (аварийный CLI-сброс)';

    public function handle(): int
    {
        $email = $this->argument('email');
        $user = User::whereEmail($email)->first();

        if (!$user) {
            $this->error("Пользователь {$email} не найден.");
            return self::FAILURE;
        }

        $user->two_factor_secret = null;
        $user->two_factor_confirmed_at = null;
        $user->save();

        $this->info("2FA сброшена для {$user->email}.");
        return self::SUCCESS;
    }
}
