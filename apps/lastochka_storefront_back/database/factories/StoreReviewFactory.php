<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Order;
use App\Models\Store;
use App\Models\StoreReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoreReview>
 */
final class StoreReviewFactory extends Factory
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
            'user_id' => User::factory(),
            'order_id' => null,
            'rating' => fake()->numberBetween(1, 5),
            'description' => fake()->optional(0.7)->sentence(),
            'author_name' => fake()->name(),
            'author_phone' => '+7'.fake()->numerify('9#########'),
            'is_published' => false,
            'published_at' => null,
        ];
    }

    /**
     * Опубликованный отзыв.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_published' => true,
            'published_at' => now(),
        ]);
    }

    /**
     * Отзыв с привязкой к заказу.
     */
    public function withOrder(): static
    {
        return $this->state(fn (array $attributes) => [
            'order_id' => Order::factory(),
        ]);
    }

    /**
     * Положительный отзыв (4-5 звёзд).
     */
    public function positive(): static
    {
        return $this->state(fn (array $attributes) => [
            'rating' => fake()->numberBetween(4, 5),
        ]);
    }

    /**
     * Негативный отзыв (1-2 звёзды).
     */
    public function negative(): static
    {
        return $this->state(fn (array $attributes) => [
            'rating' => fake()->numberBetween(1, 2),
        ]);
    }

    /**
     * Внешний отзыв (из RabbitMQ) — без user_id.
     */
    public function external(string $source = 'bagisto'): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => $source,
            'user_id' => null,
        ]);
    }
}
