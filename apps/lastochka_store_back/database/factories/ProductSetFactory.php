<?php

namespace Database\Factories;

use App\Helpers\FakerHelper;
use App\Models\Product;
use App\Models\SetCategory;
use App\Models\Type;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductSet>
 */
class ProductSetFactory extends Factory
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
      'description' => $this->faker->realText(),
      'proteins' => $this->faker->realText(20),
      'fats' => $this->faker->realText(20),
      'carbohydrates' => $this->faker->realText(20),
      'calories' => $this->faker->realText(20),
      'type_id' => Type::SET_SYSTEM,
      'main'=>$this->faker->boolean()
    ];
  }

  protected function callAfterCreating(Collection $instances, ?Model $parent = null)
  {
    parent::callAfterCreating($instances, $parent);
    foreach ($instances as $instance) {
      $instance->products()->sync(Product::inRandomOrder()->limit(5)->get());
      $instance->saveImg(FakerHelper::generateFakeImage());
      $instance->categories()->sync(SetCategory::factory()->create());

    }
  }
}
