<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LinkType;
use App\Models\Story;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Story>
 */
final class StoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => null,
            'external_id' => null,
            'title' => fake()->sentence(3),
            'thumbnail_path' => 'https://picsum.photos/seed/story'.fake()->randomNumber(5).'/200/200',
            'link_type' => LinkType::None,
            'link_product_id' => null,
            'link_store_id' => null,
            'link_external_url' => null,
            'expires_at' => now()->addDays(fake()->numberBetween(1, 30)),
            'is_active' => true,
            'position' => fake()->numberBetween(0, 10),
        ];
    }

    /**
     * Истекшая история.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }

    /**
     * Неактивная история.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * С ссылкой на внешний URL.
     */
    public function withExternalLink(): static
    {
        return $this->state(fn (array $attributes) => [
            'link_type' => LinkType::External,
            'link_external_url' => fake()->url(),
        ]);
    }
}
