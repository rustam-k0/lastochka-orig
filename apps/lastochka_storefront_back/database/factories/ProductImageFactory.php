<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductImage>
 */
final class ProductImageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'path' => 'https://picsum.photos/seed/prod'.fake()->randomNumber(5).'/400/400',
            'position' => fake()->numberBetween(0, 5),
            'created_at' => now(),
        ];
    }
}
