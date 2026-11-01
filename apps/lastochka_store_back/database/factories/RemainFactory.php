<?php

namespace Database\Factories;

use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Remain>
 */
class RemainFactory extends Factory
{
  /**
   * Define the model's default state.
   *
   * @return array<string, mixed>
   */
  public function definition()
  {
    return [
      'quantity' => rand(1, 5),
      'shop_id' => Shop::inRandomOrder()->first()->id
    ];
  }

  public function product($product_id)
  {
    return $this->state(function (array $attributes) use ($product_id) {
      return [
        'product_id' => $product_id,
      ];
    });
  }
}
