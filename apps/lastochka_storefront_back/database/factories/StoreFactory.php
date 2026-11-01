<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Store>
 */
final class StoreFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('STORE-####'),
            'source' => fake()->randomElement(['bagisto', 'retailcrm']),
            'name' => 'Магазин '.fake()->company(),
            'image_path' => fake()->boolean(70) ? 'https://picsum.photos/seed/store'.fake()->randomNumber(5).'/400/400' : null,
            'min_order_amount' => fake()->optional(0.8)->numberBetween(300, 1500),
            'delivery_price' => fake()->optional(0.8)->numberBetween(100, 500),
            'free_delivery_from' => fake()->optional(0.6)->numberBetween(1000, 5000),
            'rating' => null,
            'latitude' => fake()->latitude(55.5, 56.0),
            'longitude' => fake()->longitude(37.3, 37.9),
            'slot_duration' => fake()->randomElement([60, 90, 120]),
            'is_open_24_7' => false,
            'is_active' => true,
        ];
    }

    /**
     * Магазин работает круглосуточно.
     */
    public function open247(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_open_24_7' => true,
        ]);
    }

    /**
     * B2B-склад.
     */
    public function b2b(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_b2b' => true,
        ]);
    }

    /**
     * Магазин не учитывает остатки (shops.ignore_stock).
     */
    public function ignoreStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'ignore_stock' => true,
        ]);
    }

    /**
     * Неактивный магазин.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Магазин с расписанием на все дни недели (9:00-21:00).
     */
    public function withSchedule(): static
    {
        return $this->afterCreating(function (Store $store) {
            for ($day = 1; $day <= 7; $day++) {
                $store->schedules()->create([
                    'day_of_week' => $day,
                    'opens_at' => '09:00',
                    'closes_at' => '21:00',
                ]);
            }
        });
    }
}
