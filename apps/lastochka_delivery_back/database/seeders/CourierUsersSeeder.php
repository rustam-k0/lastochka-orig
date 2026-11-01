<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CourierUsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
      $user = User::create([
        'name' => 'Курьер',
        'email' => 'courier@mail.com',
        'phone'=> env('COURIER_PHONE') ?: '+79222222222',
        'password' => bcrypt(env('COURIER_PASSWORD') ?: '12345678'),
        'email_verified_at' => now(),
      ]);

      $user->assignRole(Role::COURIER);
    }
}
