<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Country;
use App\Models\Manufacturer;
use App\Models\Price;
use App\Models\ProductGroup;
use App\Models\ProductSet;
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
    Brand::factory(5)->create();
    Country::factory(5)->create();
    Manufacturer::factory(5)->create();
    Price::factory(50)->create();
    ProductGroup::factory(5)->create();
    ProductSet::factory(3)->create();

  }

}
