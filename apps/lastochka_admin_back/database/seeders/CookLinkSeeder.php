<?php

namespace Database\Seeders;

use App\Helpers\FakerHelper;
use App\Models\CookLink;
use Illuminate\Database\Seeder;

class CookLinkSeeder extends Seeder
{
  /**
   * Run the database seeds.
   *
   * @return void
   */
  public function run()
  {
    $links = [
      "Салаты",
      "Чуду",
      "Роллы",
      "Гарниры",
      "Шашлыки",
      "Фаст фуд",
      "Роллы 2",
      "Гарниры 2",
    ];

    foreach ($links as $key => $link) {

      $cook_link = CookLink::create([
        "title" => $link,
        "link" => "https://google.com",
        "position" => $key + 1,
      ]);

      $cook_link->saveImg(FakerHelper::generateFakeImage());
    }
  }
}
