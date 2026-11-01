<?php

namespace Database\Factories;

use App\Models\Category1c;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Category1c>
 */
class Category1cFactory extends Factory
{
  protected $model = Category1c::class;

  public function definition(): array
  {
    return [
      'shop_id' => ShopFactory::new(),
      'external_id' => (string) Str::uuid(),
      'parent_id' => null,
      'name' => $this->faker->unique()->words(2, true),
      'position' => 0,
      'is_active' => true,
      'linked_at' => null,
      'last_synced_at' => null,
      'synced_from_1c_at' => now(),
    ];
  }
}
