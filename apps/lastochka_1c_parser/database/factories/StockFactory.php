<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Stock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Stock>
 */
class StockFactory extends Factory
{
    protected $model = Stock::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'warehouse_id' => $this->faker->uuid(),
            'warehouse_name' => $this->faker->words(2, true),
            'quantity' => $this->faker->randomFloat(3, 0, 1000),
            'synced_at' => now(),
        ];
    }

    public function empty(): static
    {
        return $this->state(fn () => ['quantity' => 0]);
    }
}
