<?php

namespace Database\Factories;

use App\Models\Image;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Image>
 */
class ImageFactory extends Factory
{
    protected $model = Image::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'path' => 'products/' . $this->faker->uuid() . '.jpg',
            'external_id' => $this->faker->uuid(),
            'sort' => $this->faker->numberBetween(0, 10),
            'is_main' => false,
        ];
    }

    public function main(): static
    {
        return $this->state(fn () => ['is_main' => true, 'sort' => 0]);
    }
}
