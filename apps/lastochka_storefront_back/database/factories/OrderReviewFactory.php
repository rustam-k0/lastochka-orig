<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderReview>
 */
final class OrderReviewFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'user_id' => User::factory(),
            'rating' => fake()->numberBetween(1, 5),
            'description' => fake()->optional(0.7)->sentence(),
            'author_name' => fake()->name(),
            'author_phone' => '+7'.fake()->numerify('9#########'),
            'source' => 'storefront',
        ];
    }
}
