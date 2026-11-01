<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BonusTransaction;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BonusTransaction>
 */
final class BonusTransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'order_id' => Order::factory(),
            'type' => fake()->randomElement(['earn', 'spend', 'refund']),
            'amount' => fake()->numberBetween(10, 1000),
            'balance_after' => fake()->numberBetween(0, 5000),
            'description' => fake()->sentence(),
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

    public function refund(): static
    {
        return $this->state(fn (array $attributes) => ['type' => 'refund']);
    }
}
