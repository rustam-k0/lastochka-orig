<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductGroupItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductGroupItem>
 */
final class ProductGroupItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_group_id' => ProductGroup::factory(),
            'product_id' => Product::factory(),
            'position' => fake()->numberBetween(0, 20),
            'created_at' => now(),
        ];
    }
}
