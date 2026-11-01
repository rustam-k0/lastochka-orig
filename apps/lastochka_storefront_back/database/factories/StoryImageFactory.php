<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Story;
use App\Models\StoryImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoryImage>
 */
final class StoryImageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'story_id' => Story::factory(),
            'path' => 'https://picsum.photos/seed/storyimg'.fake()->randomNumber(5).'/400/700',
            'position' => fake()->numberBetween(0, 5),
            'created_at' => now(),
        ];
    }
}
