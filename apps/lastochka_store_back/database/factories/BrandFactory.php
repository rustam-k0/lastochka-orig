<?php

namespace Database\Factories;

use App\Helpers\FakerHelper;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Brand>
 */
class BrandFactory extends Factory
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
      'categories_page' => $this->faker->boolean,
    ];
  }

  protected function callAfterCreating(Collection $instances, ?Model $parent = null)
  {
    foreach ($instances as $instance) {
      $instance->saveImg(FakerHelper::generateFakeImage());
    }
  }
}
