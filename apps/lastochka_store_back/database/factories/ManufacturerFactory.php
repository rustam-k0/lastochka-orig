<?php

namespace Database\Factories;

use App\Helpers\FakerHelper;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Manufacturer>
 */
class ManufacturerFactory extends Factory
{
  /**
   * Define the model's default state.
   *
   * @return array<string, mixed>
   */
  public function definition()
  {
    return [
      'title' => $this->faker->realText(20),
      'icon' => $this->faker->randomElement(FakerHelper::getSvgArray())
    ];
  }
}
