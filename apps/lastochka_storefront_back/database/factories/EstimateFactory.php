<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Estimate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Estimate>
 */
final class EstimateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->sentence(3),
            'description' => fake()->optional()->sentence(),
        ];
    }
}
