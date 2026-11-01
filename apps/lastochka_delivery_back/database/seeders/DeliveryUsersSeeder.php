<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DeliveryUsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
      $user = User::create([
        'name' => 'Сборщик',
        'email' => 'collector@mail.com',
        'phone'=> env('COLLECTOR_PHONE') ?: '+79111111111',
        'password' => bcrypt(env('COLLECTOR_PASSWORD') ?: '12345678'),
        'email_verified_at' => now(),
      ]);

      $user->assignRole(Role::COLLECTOR);
    }
}
