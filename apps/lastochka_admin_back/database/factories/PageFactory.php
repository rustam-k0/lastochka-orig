<?php

namespace Database\Factories;

use App\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Page>
 */
class PageFactory extends Factory
{
    protected $model = Page::class;

    public function definition(): array
    {
        return [
            'slug' => 'page-' . Str::lower(Str::random(8)),
            'title' => 'О компании',
            'content' => json_encode([
                'blocks' => [
                    ['type' => 'paragraph', 'data' => ['text' => 'Текст страницы']],
                ],
            ], JSON_UNESCAPED_UNICODE),
            'meta_title' => null,
            'meta_description' => null,
            'position' => null,
            'is_published' => false,
            'published_at' => null,
            'is_system' => false,
        ];
    }

    /**
     * Опубликованная страница.
     */
    public function published(): static
    {
        return $this->state(fn () => [
            'is_published' => true,
            'published_at' => now(),
        ]);
    }

    /**
     * Системная страница: удалять и переименовывать по коду нельзя.
     */
    public function system(): static
    {
        return $this->state(fn () => [
            'is_system' => true,
        ]);
    }
}
