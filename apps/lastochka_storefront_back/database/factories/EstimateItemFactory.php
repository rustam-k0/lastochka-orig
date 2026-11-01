<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EstimateItem>
 */
final class EstimateItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'estimate_id' => Estimate::factory(),
            'product_id' => Product::factory(),
            'quantity' => fake()->numberBetween(1, 10),
        ];
    }
}
