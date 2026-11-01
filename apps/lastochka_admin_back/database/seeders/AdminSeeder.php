<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Laravel\Sanctum\PersonalAccessToken;

class AdminSeeder extends Seeder
{
  /**
   * Run the database seeds.
   *
   * @return void
   */
  public function run()
  {
    // Администратор
    $user = User::create([
      'name' => 'Admin',
      'email' => env('ADMIN_EMAIL', 'admin@mail.com'),
      'password' => bcrypt(env('ADMIN_PASSWORD', '12345678')),
      'email_verified_at' => now(),
    ]);

    $user->assignRole(Role::ADMIN);

    // Как бутстрап миграции 2026_05_26_120000_add_superadmin_role на стендах с историей:
    // без супер-админа при включённой матрице доступов Настройки недоступны никому
    if (Role::where('name', Role::SUPERADMIN)->exists()) {
      $user->assignRole(Role::SUPERADMIN);
    }


    if (env('ADMIN_TOKEN')) {
      $this->generateToken($user, env('ADMIN_TOKEN'));
    }
    $user->createToken('1')->plainTextToken;

  }


  /**
   * Генерация токена
   */
  public function generateToken($user, $token)
  {
    if (env('ADMIN_TOKEN') && str_contains((string) $token, '|')) {
      [$id, $generated] = explode('|', $token, 2);
      PersonalAccessToken::create([
        'id' => $id,
        'name' => 'ADMIN_TOKEN',
        'token' => hash('sha256', $generated),
        'abilities' => ['*'],
        'tokenable_type' => User::class,
        'tokenable_id' => $user->id
      ]);
    }
  }
}
