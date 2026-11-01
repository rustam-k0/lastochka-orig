<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Preorder;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Preorder> */
final class PreorderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'product_id' => Product::factory(),
            'store_id' => Store::factory(),
            'full_name' => fake()->name(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->optional(0.7)->safeEmail(),
            'quantity' => fake()->numberBetween(1, 10),
            'comment' => fake()->optional(0.3)->sentence(),
            'status' => 'new',
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => ['status' => 'completed']);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => 'cancelled']);
    }
}
