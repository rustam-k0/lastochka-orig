<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class FakerSeeder extends Seeder
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



  }

}
