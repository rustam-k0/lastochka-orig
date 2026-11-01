<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderAddress>
 */
final class OrderAddressFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'original_id' => null,
            'country' => 'Россия',
            'city' => fake()->randomElement(['Москва', 'Санкт-Петербург', 'Казань']),
            'street' => fake()->streetName(),
            'house_number' => (string) fake()->numberBetween(1, 200),
            'entrance' => fake()->optional(0.7)->numberBetween(1, 10),
            'floor' => fake()->optional(0.7)->numberBetween(1, 25),
            'apartment' => fake()->optional(0.7)->numberBetween(1, 500),
            'intercom' => fake()->optional(0.5)->numberBetween(1, 9999),
            'comment' => fake()->optional(0.3)->sentence(),
            'full_address' => null,
            'latitude' => fake()->latitude(55.5, 56.0),
            'longitude' => fake()->longitude(37.3, 37.9),
            'created_at' => now(),
        ];
    }
}
