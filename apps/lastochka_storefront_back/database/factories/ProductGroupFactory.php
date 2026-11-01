<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProductGroup;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductGroup>
 */
final class ProductGroupFactory extends Factory
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
            'title' => fake()->randomElement([
                'Хиты продаж',
                'Новинки',
                'Скидки недели',
                'Рекомендуемое',
                'Популярное',
                'Сезонные товары',
            ]),
            'image_path' => fake()->boolean(50) ? 'https://picsum.photos/seed/group'.fake()->randomNumber(5).'/400/300' : null,
            'meta' => fake()->optional(0.3)->randomElement([
                ['subtitle' => 'Самые популярные товары'],
                ['subtitle' => 'Лучшие предложения'],
            ]),
            'show_on_store_page' => false,
            'show_in_catalog' => false,
            'show_on_home' => false,
            'show_on_search' => false,
            'position' => fake()->numberBetween(0, 10),
            'is_active' => true,
        ];
    }

    /**
     * Неактивная группа.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Показывать на странице мерчанта.
     */
    public function onStorePage(): static
    {
        return $this->state(fn (array $attributes) => [
            'show_on_store_page' => true,
        ]);
    }

    /**
     * Показывать в каталоге.
     */
    public function inCatalog(): static
    {
        return $this->state(fn (array $attributes) => [
            'show_in_catalog' => true,
        ]);
    }

    /**
     * Показывать на главной.
     */
    public function onHome(): static
    {
        return $this->state(fn (array $attributes) => [
            'show_on_home' => true,
        ]);
    }

    /**
     * Показывать на странице поиска.
     */
    public function onSearch(): static
    {
        return $this->state(fn (array $attributes) => [
            'show_on_search' => true,
        ]);
    }

    /**
     * Комплексное решение (фасетный каталог).
     */
    public function complexSolution(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_complex_solution' => true,
        ]);
    }
}
