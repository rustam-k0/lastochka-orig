<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
final class CategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'parent_id' => null,
            'name' => ucfirst($name),
            'slug' => Str::slug($name),
            'image_path' => fake()->boolean(50) ? 'https://picsum.photos/seed/cat'.fake()->randomNumber(5).'/200/200' : null,
            'position' => fake()->numberBetween(0, 100),
            'is_active' => true,
            'meta_title' => null,
            'meta_description' => null,
        ];
    }

    /**
     * Неактивная категория.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Категория, помеченная как «показывать на главной».
     */
    public function showOnMain(): static
    {
        return $this->state(fn (array $attributes) => [
            'show_on_main' => true,
        ]);
    }

    /**
     * Категория с фоновым изображением.
     */
    public function withBackground(string $path = 'categories/backgrounds/seed.png'): static
    {
        return $this->state(fn (array $attributes) => [
            'background_image_path' => $path,
        ]);
    }

    /**
     * Дочерняя категория.
     */
    public function childOf(Category $parent): static
    {
        return $this->state(fn (array $attributes) => [
            'parent_id' => $parent->id,
        ]);
    }

    /**
     * Привязать к магазину через pivot после создания.
     */
    public function forStore(Store $store, array $pivotAttributes = []): static
    {
        return $this->afterCreating(function (Category $category) use ($store, $pivotAttributes) {
            $category->stores()->syncWithoutDetaching([
                $store->id => array_merge([
                    'position' => $category->position ?? 0,
                    'is_active' => $category->is_active,
                ], $pivotAttributes),
            ]);
        });
    }
}
