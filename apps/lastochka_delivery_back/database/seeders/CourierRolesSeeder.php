<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class CourierRolesSeeder extends Seeder
{
  /**
   * Run the database seeds.
   *
   * @return void
   */
  public function run()
  {
    // Роль уже может создать миграция admin_back 2023_05_08_190600_create_system_roles
    Role::firstOrCreate(['name' => Role::COURIER], ['name_ru' => 'Курьер']);
  }
}
