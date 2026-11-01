<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
final class ProductFactory extends Factory
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
            'sku' => fake()->unique()->bothify('SKU-####-??'),
            'title' => fake()->words(3, true),
            'description' => fake()->optional(0.7)->sentence(),
            'price' => fake()->numberBetween(50, 5000),
            'price_old' => null,
            'currency' => 'RUB',
            'quantity' => fake()->numberBetween(1, 100),
            'unit_type' => 'piece',
            'is_active' => true,
        ];
    }

    /**
     * Привязать товар к категории через pivot category_product.
     */
    public function inCategory(Category $category, int $position = 0): static
    {
        return $this->afterCreating(function (Product $product) use ($category, $position) {
            $product->categories()->syncWithoutDetaching([
                $category->id => ['position' => $position],
            ]);
        });
    }

    /**
     * Товар со скидкой (price_old установлена).
     */
    public function withDiscount(): static
    {
        return $this->state(function (array $attributes) {
            $priceOld = fake()->numberBetween(200, 5000);

            return [
                'price_old' => $priceOld,
                'price' => (int) round($priceOld * fake()->randomFloat(2, 0.5, 0.9)),
            ];
        });
    }

    /**
     * Товар не в наличии.
     */
    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity' => 0,
        ]);
    }

    /**
     * Неактивный товар.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Товар с изображениями.
     */
    public function withImages(int $count = 1): static
    {
        return $this->has(ProductImage::factory()->count($count), 'images');
    }
}
