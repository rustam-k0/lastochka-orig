<?php

namespace Database\Factories;

use App\Models\MerchantSale;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

class MerchantSaleFactory extends Factory
{
    protected $model = MerchantSale::class;

    public function definition(): array
    {
        $startsAt = $this->faker->dateTimeBetween('now', '+1 week');
        $endsAt = $this->faker->dateTimeBetween($startsAt, '+1 month');

        return [
            'title' => $this->faker->sentence(3),
            'description' => $this->faker->optional()->paragraph(),
            'shop_id' => Shop::factory(),
            'discount_percent' => $this->faker->numberBetween(5, 50),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'is_active' => true,
        ];
    }

    public function upcoming(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addWeek(),
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addWeek(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => now()->subWeek(),
            'ends_at' => now()->subDay(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
