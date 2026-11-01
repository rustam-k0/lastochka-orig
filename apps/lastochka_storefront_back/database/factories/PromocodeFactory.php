<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PromocodeType;
use App\Models\Promocode;
use App\Models\PromocodeUsage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Promocode>
 */
final class PromocodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->words(3, true),
            'code' => fake()->unique()->bothify('PROMO-####'),
            'type' => PromocodeType::Percent,
            'value' => fake()->numberBetween(5, 30),
            'min_order_amount' => null,
            'store_id' => null,
            'source' => null,
            'external_id' => null,
            'max_uses' => null,
            'max_uses_per_client' => null,
            'used_count' => 0,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'is_active' => true,
        ];
    }

    /**
     * Промокод с фиксированной скидкой.
     */
    public function fixed(int $value = 100): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => PromocodeType::Fixed,
            'value' => $value,
        ]);
    }

    /**
     * Промокод с процентной скидкой.
     */
    public function percent(int $value = 10): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => PromocodeType::Percent,
            'value' => $value,
        ]);
    }

    /**
     * Истёкший промокод.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
        ]);
    }

    /**
     * Неактивный промокод.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Промокод с исчерпанным лимитом: лимит считается по использованиям
     * в неотменённых заказах, одного used_count недостаточно.
     */
    public function exhausted(): static
    {
        return $this->state(fn (array $attributes) => [
            'max_uses' => 10,
            'used_count' => 10,
        ])->afterCreating(function (Promocode $promocode) {
            for ($i = 0; $i < $promocode->max_uses; $i++) {
                PromocodeUsage::create([
                    'promocode_id' => $promocode->id,
                    'user_id' => User::factory()->create()->id,
                    'order_id' => null,
                    'used_at' => now(),
                ]);
            }
        });
    }

    /**
     * Промокод с минимальной суммой заказа.
     */
    public function withMinAmount(int $amount): static
    {
        return $this->state(fn (array $attributes) => [
            'min_order_amount' => $amount,
        ]);
    }
}
