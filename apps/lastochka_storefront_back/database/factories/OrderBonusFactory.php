<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderBonus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderBonus>
 */
final class OrderBonusFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'type' => fake()->randomElement(['earn', 'spend']),
            'percent' => fake()->numberBetween(1, 20),
            'eligible_amount' => fake()->numberBetween(500, 5000),
            'amount' => fake()->numberBetween(10, 500),
            'created_at' => now(),
        ];
    }

    public function earn(): static
    {
        return $this->state(fn (array $attributes) => ['type' => 'earn']);
    }

    public function spend(): static
    {
        return $this->state(fn (array $attributes) => ['type' => 'spend']);
    }
}
