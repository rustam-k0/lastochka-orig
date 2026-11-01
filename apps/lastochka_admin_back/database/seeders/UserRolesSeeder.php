<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class UserRolesSeeder extends Seeder
{
  /**
   * Run the database seeds.
   *
   * @return void
   */
  public function run()
  {
    // Роли уже может создать миграция 2023_05_08_190600_create_system_roles
    Role::firstOrCreate(['name' => Role::ADMIN], ['name_ru' => 'Администратор']);

    Role::firstOrCreate(['name' => Role::SHOP], ['name_ru' => 'Селер']);
  }
}
