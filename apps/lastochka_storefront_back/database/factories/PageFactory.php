<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Page>
 */
final class PageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => 'bagisto',
            'external_id' => (string) fake()->unique()->numberBetween(1, 100000),
            'slug' => 'page-'.Str::lower(Str::random(6)),
            'title' => 'О компании',
            'content' => json_encode([
                'blocks' => [
                    ['type' => 'paragraph', 'data' => ['text' => 'Текст страницы']],
                ],
            ], JSON_UNESCAPED_UNICODE),
            'meta_title' => null,
            'meta_description' => null,
            'position' => fake()->numberBetween(1, 10),
            'is_published' => true,
            'published_at' => now(),
        ];
    }

    /**
     * Черновик: витрина такую страницу не отдаёт.
     */
    public function unpublished(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_published' => false,
            'published_at' => null,
        ]);
    }
}
