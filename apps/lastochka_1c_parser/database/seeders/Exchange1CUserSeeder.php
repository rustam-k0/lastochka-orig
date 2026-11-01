<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeder для создания пользователя обмена с 1С
 *
 * php artisan db:seed --class=Exchange1CUserSeeder
 */
class Exchange1CUserSeeder extends Seeder
{
    public function run(): void
    {
        $login = config('exchange1c.exchange_login', '1c_exchange');
        $password = config('exchange1c.exchange_password');

        if (empty($password)) {
            $password = 'change_me_' . bin2hex(random_bytes(8));
            $this->command->warn("Сгенерирован пароль: {$password}");
            $this->command->warn("Добавьте его в .env: 1C_EXCHANGE_PASSWORD={$password}");
        }

        User::updateOrCreate(
            ['email' => $login],
            [
                'name' => '1C Exchange User',
                'password' => Hash::make($password),
            ]
        );

        $this->command->info("Пользователь для обмена с 1С создан: {$login}");
    }
}
