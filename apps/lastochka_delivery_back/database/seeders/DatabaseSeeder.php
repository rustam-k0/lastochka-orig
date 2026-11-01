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
      DeliveryRolesSeeder::class,
      DeliveryUsersSeeder::class,
      ExplainReasonSeeder::class,
      CourierRolesSeeder::class,
      CourierUsersSeeder::class
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
