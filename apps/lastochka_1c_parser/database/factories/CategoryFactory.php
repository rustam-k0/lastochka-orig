<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        return [
            // categories.shop_id — NOT NULL с multishop-миграции 2026_03_29_200001
            'shop_id' => 1,
            'external_id' => $this->faker->uuid(),
            'parent_id' => null,
            'name' => $this->faker->words(3, true),
            'sort' => $this->faker->numberBetween(0, 100),
            'is_active' => true,
            'synced_at' => now(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
