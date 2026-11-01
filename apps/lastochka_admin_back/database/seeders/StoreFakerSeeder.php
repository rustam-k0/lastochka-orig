<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Price;
use Illuminate\Database\Seeder;

class StoreFakerSeeder extends Seeder
{
  /**
   * Run the database seeds.
   *
   * @return void
   */
  public function run()
  {
    $enabled = env('ENABLE_FAKER');

    if (!$enabled) {
      return;
    }


    Category::factory(3)->create();
    Category::factory(7)->create();
    Price::factory(50)->create();

  }

}
