<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Promotion;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Promotion>
 */
final class PromotionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'source' => null,
            'external_id' => null,
            'title' => fake()->sentence(3),
            // Promotion::getRouteKeyName() = 'slug': без него роут
            // /api/v1/promotions/{promotion} не резолвится и отдаёт 404.
            'slug' => fake()->unique()->slug(3),
            'description' => fake()->optional(0.7)->paragraph(),
            'image_path' => fake()->boolean(50) ? 'https://picsum.photos/seed/promo'.fake()->randomNumber(5).'/800/400' : null,
            'discount_percent' => fake()->numberBetween(5, 50),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addWeek(),
            'is_active' => true,
            'position' => fake()->numberBetween(0, 100),
        ];
    }

    /**
     * Неактивная акция.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Истёкшая акция.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subWeek(),
        ]);
    }

    /**
     * Будущая акция (ещё не началась).
     */
    public function upcoming(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addMonth(),
        ]);
    }

    /**
     * С конкретным процентом скидки.
     */
    public function withDiscountPercent(int $percent): static
    {
        return $this->state(fn (array $attributes) => [
            'discount_percent' => $percent,
        ]);
    }
}
