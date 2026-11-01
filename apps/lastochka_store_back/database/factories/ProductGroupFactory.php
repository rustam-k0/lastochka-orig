<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Type;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductGroup>
 */
class ProductGroupFactory extends Factory
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
      'stocks_sets' => $this->faker->boolean(30),
      'sets_cooking' => $this->faker->boolean(30),
      'after_cooking' => $this->faker->boolean(30),
      'type_id' => Type::PRODUCT_GROUP_PRODUCTS,
      'position' => rand(1, 10)
    ];
  }

  protected function callAfterCreating(Collection $instances, ?Model $parent = null)
  {
    parent::callAfterCreating($instances, $parent);
    foreach ($instances as $instance) {
      $instance->products()->sync(Product::inRandomOrder()->limit(5)->get());
    }
  }
}
