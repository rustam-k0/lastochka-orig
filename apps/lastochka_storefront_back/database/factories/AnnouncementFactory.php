<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Announcement;
use App\Models\Promocode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
final class AnnouncementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => 'admin',
            'external_id' => fake()->unique()->numerify('ann_####'),
            'title' => fake()->sentence(4),
            'short_description' => fake()->sentence(8),
            'description' => fake()->paragraphs(2, true),
            'image_path' => 'https://picsum.photos/seed/announcement'.fake()->randomNumber(5).'/800/600',
            'promocode_id' => null,
            'is_active' => true,
        ];
    }

    /**
     * Уведомление с промокодом.
     */
    public function withPromocode(?Promocode $promocode = null): static
    {
        return $this->state(fn (array $attributes) => [
            'promocode_id' => $promocode?->id ?? Promocode::factory(),
        ]);
    }

    /**
     * Неактивное уведомление.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Уведомление от определённого источника.
     */
    public function fromSource(string $source): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => $source,
        ]);
    }

    /**
     * Уведомление без изображения.
     */
    public function withoutImage(): static
    {
        return $this->state(fn (array $attributes) => [
            'image_path' => null,
        ]);
    }

    /**
     * Уведомление с коротким описанием.
     */
    public function shortContent(): static
    {
        return $this->state(fn (array $attributes) => [
            'title' => fake()->words(3, true),
            'short_description' => fake()->sentence(4),
            'description' => fake()->sentence(6),
        ]);
    }
}