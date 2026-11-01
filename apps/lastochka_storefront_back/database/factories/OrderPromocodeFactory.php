<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PromocodeType;
use App\Models\Order;
use App\Models\OrderPromocode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderPromocode>
 */
final class OrderPromocodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(PromocodeType::cases());
        $value = $type === PromocodeType::Percent
            ? fake()->numberBetween(5, 30)
            : fake()->numberBetween(100, 500);

        return [
            'order_id' => Order::factory(),
            'original_id' => null,
            'code' => fake()->unique()->regexify('[A-Z]{6}[0-9]{2}'),
            'type' => $type,
            'value' => $value,
            'discount_amount' => fake()->numberBetween(50, 500),
            'created_at' => now(),
        ];
    }

    /**
     * Процентный промокод.
     */
    public function percent(int $value = 10): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => PromocodeType::Percent,
            'value' => $value,
        ]);
    }

    /**
     * Фиксированный промокод.
     */
    public function fixed(int $value = 100): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => PromocodeType::Fixed,
            'value' => $value,
        ]);
    }
}
