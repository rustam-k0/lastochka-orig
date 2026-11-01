<?php

namespace Database\Factories;

use App\Models\MerchantReview;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

class MerchantReviewFactory extends Factory
{
    protected $model = MerchantReview::class;

    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            'rating' => $this->faker->numberBetween(1, 5),
            'description' => $this->faker->optional()->paragraph(),
            'is_published' => false,
            'author_name' => $this->faker->optional()->name(),
            'author_phone' => $this->faker->optional()->numerify('+7##########'),
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_published' => true,
        ]);
    }

    public function unpublished(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_published' => false,
        ]);
    }

    public function withRating(int $rating): static
    {
        return $this->state(fn (array $attributes) => [
            'rating' => $rating,
        ]);
    }

    public function withAuthor(string $name, ?string $phone = null): static
    {
        return $this->state(fn (array $attributes) => [
            'author_name' => $name,
            'author_phone' => $phone,
        ]);
    }
}
