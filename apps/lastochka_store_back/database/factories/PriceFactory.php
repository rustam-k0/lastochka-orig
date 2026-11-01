<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Shop;
use App\Models\Type;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Price>
 */
class PriceFactory extends Factory
{
  /**
   * Define the model's default state.
   *
   * @return array<string, mixed>
   */
  public function definition()
  {
    return [
      'product_id' => Product::factory(),
      'type_id' => Type::PRICE_DEFAULT,
      'value' => rand(100, 1000),
      'step' => rand(5, 10),
      'shop_id' => Shop::first()->id,

    ];
  }
}
