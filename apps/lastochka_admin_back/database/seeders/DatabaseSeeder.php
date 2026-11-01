<?php

namespace Database\Seeders;

use App\Models\Seeder as DBSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
  /**
   * Seed the application's database.
   *
   * @return void
   */
  public function run()
  {
    /**
     * Здесь сидеры которые должны
     * запускаться только один раз
     */
    $seeders = [
      // Бывшие store_back сидеры (должны идти первыми)
      DefaultValuesSeeder::class,
      ShopSeeder::class,
      AttributeSeeder::class,
      StoreFakerSeeder::class,
      // admin_back сидеры
      UserRolesSeeder::class,
      AdminSeeder::class,
      ApiRoleSeeder::class
    ];

    /**
     * Запускаем только те сидеры,
     * которые не были запущены
     */
    foreach ($seeders as $seeder) {
      if (!DBSeeder::where('class', $seeder)->first()) {
        $this->call($seeder);
        DBSeeder::create([
          'class' => $seeder
        ]);
      }
    }
  }
}
