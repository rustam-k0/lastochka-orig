<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LinkType;
use App\Models\Banner;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Banner>
 */
final class BannerFactory extends Factory
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
            'image_path' => 'https://picsum.photos/seed/banner'.fake()->randomNumber(5).'/800/400',
            'link_type' => LinkType::None,
            'link_product_id' => null,
            'link_category_id' => null,
            'display_category_id' => null,
            'position' => fake()->numberBetween(0, 10),
            'is_active' => true,
            'starts_at' => null,
            'ends_at' => null,
        ];
    }

    /**
     * Неактивный баннер.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Баннер с датами показа.
     */
    public function scheduled(\DateTimeInterface $startsAt, \DateTimeInterface $endsAt): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }

    /**
     * Баннер, чей период показа уже истёк.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDay(),
        ]);
    }

    /**
     * Баннер, чей период ещё не начался.
     */
    public function future(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(10),
        ]);
    }
}
