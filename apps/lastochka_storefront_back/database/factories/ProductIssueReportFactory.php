<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductIssueReport;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductIssueReport> */
final class ProductIssueReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'product_id' => Product::factory(),
            'store_id' => Store::factory(),
            'email' => fake()->safeEmail(),
            'type' => fake()->randomElement(['description_error', 'missing_characteristics']),
            'message' => fake()->sentence(),
        ];
    }
}
