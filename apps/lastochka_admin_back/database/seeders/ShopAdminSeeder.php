<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Laravel\Sanctum\PersonalAccessToken;

class ShopAdminSeeder extends Seeder
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
      'name' => 'Shop',
      'email' => 'shop@mail.com',
      'password' => bcrypt(env('ADMIN_PASSWORD', '12345678')),
      'email_verified_at' => now(),
    ]);

    $user->assignRole(Role::SHOP);
  }
}
