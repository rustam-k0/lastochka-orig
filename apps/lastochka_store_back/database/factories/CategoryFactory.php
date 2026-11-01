<?php

namespace Database\Factories;

use App\Helpers\FakerHelper;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Category>
 */
class CategoryFactory extends Factory
{
  /**
   * Define the model's default state.
   *
   * @return array<string, mixed>
   */
  public function definition()
  {
    $title = $this->faker->realText(20);
    $slug = Category::getUniqueLink($title);
    return [
      'title' => $title,
      'link' => $slug,
      'parent_id' => $this->faker->boolean(30) ? Category::whereNull('parent_id')->inRandomOrder()->first()?->id : null,
    ];
  }

  protected function callAfterCreating(Collection $instances, ?Model $parent = null)
  {
    parent::callAfterCreating($instances, $parent);
    foreach ($instances as $instance) {
      $instance->saveImg(\App\Helpers\FakerHelper::generateFakeImage(),\App\Models\Status::CODE_SITE_CATALOG_IMG);
      $instance->saveImg(\App\Helpers\FakerHelper::generateFakeImage(),\App\Models\Status::CODE_SITE_SQUARE_IMG);
      $instance->saveImg(\App\Helpers\FakerHelper::generateFakeImage(),\App\Models\Status::CODE_SITE_RECT_IMG);
      $instance->saveImg(\App\Helpers\FakerHelper::generateFakeImage(),\App\Models\Status::CODE_MOB_COLUMN_IMG);
      $instance->saveImg(\App\Helpers\FakerHelper::generateFakeImage(),\App\Models\Status::CODE_MOB_SQUARE_IMG);
      $instance->saveImg(\App\Helpers\FakerHelper::generateFakeImage(),\App\Models\Status::CODE_MOB_RECT_IMG);
    }
  }
}
