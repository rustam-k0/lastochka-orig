<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Faq;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Faq>
 */
final class FaqFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => null,
            'external_id' => null,
            'question' => fake()->sentence(4),
            'answer' => fake()->paragraph(),
            'is_active' => true,
            'position' => fake()->numberBetween(1, 10),
        ];
    }

    /**
     * Неактивный вопрос.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
