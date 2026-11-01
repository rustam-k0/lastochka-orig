<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 */
final class AddressFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->randomElement(['Дом', 'Работа', 'Дача', null]),
            'country' => 'Россия',
            'city' => fake()->randomElement(['Москва', 'Санкт-Петербург', 'Казань', 'Новосибирск']),
            'street' => fake()->streetName(),
            'house_number' => (string) fake()->numberBetween(1, 200),
            'entrance' => fake()->optional(0.7)->numberBetween(1, 10),
            'floor' => fake()->optional(0.7)->numberBetween(1, 25),
            'apartment' => fake()->optional(0.7)->numberBetween(1, 500),
            'intercom' => fake()->optional(0.5)->numberBetween(1, 9999),
            'comment' => fake()->optional(0.3)->sentence(),
            'latitude' => fake()->latitude(55.5, 56.0),
            'longitude' => fake()->longitude(37.3, 37.9),
            'is_active' => false,
        ];
    }

    /**
     * Активный адрес.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }

    /**
     * Объектный адрес (офис/склад с метаданными).
     */
    public function object(): static
    {
        return $this->state(fn () => [
            'is_object' => true,
            'object_data' => [
                'name' => 'Многоэтажка',
                'contact_name' => 'Иванов И. И.',
                'contact_phone' => '+79991234567',
                'company' => 'Звёздочка',
                'lift_size' => '1,5 на 2',
                'has_lift' => true,
                'needs_pass' => false,
                'can_oversized' => true,
                'working_hours_from' => '10:00',
                'working_hours_to' => '20:00',
                'is_24_7' => false,
                'working_days' => ['mon', 'tue', 'wed', 'thu', 'fri'],
                'is_daily' => false,
            ],
        ]);
    }

    /**
     * Московский адрес.
     */
    public function moscow(): static
    {
        return $this->state(fn (array $attributes) => [
            'city' => 'Москва',
            'latitude' => fake()->latitude(55.5, 56.0),
            'longitude' => fake()->longitude(37.3, 37.9),
        ]);
    }
}
