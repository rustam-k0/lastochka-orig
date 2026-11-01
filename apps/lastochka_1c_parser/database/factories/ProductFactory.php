<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            // products.shop_id — NOT NULL с multishop-миграции 2026_03_29_200002
            'shop_id' => 1,
            'external_id' => $this->faker->uuid(),
            // Категория создаётся в том же магазине, что и товар
            'category_id' => fn (array $attributes) => Category::factory()->state(['shop_id' => $attributes['shop_id']]),
            'name' => $this->faker->words(3, true),
            'sku' => $this->faker->unique()->regexify('[A-Z]{2}[0-9]{6}'),
            'barcode' => $this->faker->ean13(),
            'barcodes' => [$this->faker->ean13()],
            'description' => $this->faker->paragraph(),
            'attributes' => [
                'weight' => $this->faker->randomFloat(2, 0.1, 100),
                'length' => $this->faker->numberBetween(1, 500),
                'width' => $this->faker->numberBetween(1, 500),
                'height' => $this->faker->numberBetween(1, 500),
            ],
            'is_active' => true,
            'synced_at' => now(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function withoutSku(): static
    {
        return $this->state(fn () => ['sku' => null]);
    }

    public function withoutAttributes(): static
    {
        return $this->state(fn () => ['attributes' => null]);
    }

    public function withoutCategory(): static
    {
        return $this->state(fn () => ['category_id' => null]);
    }
}
