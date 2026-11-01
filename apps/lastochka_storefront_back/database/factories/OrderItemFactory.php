<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
final class OrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $price = fake()->numberBetween(50, 1000);
        $quantity = fake()->numberBetween(1, 5);

        return [
            'order_id' => Order::factory(),
            'product_id' => Product::factory(),
            'title' => fake()->words(3, true),
            'sku' => fake()->unique()->regexify('[A-Z]{3}-[0-9]{4}'),
            'price' => $price,
            'quantity' => $quantity,
            'total' => $price * $quantity,
            'image_url' => fake()->boolean(80) ? 'https://picsum.photos/seed/prod'.fake()->randomNumber(5).'/200/200' : null,
            'created_at' => now(),
        ];
    }
}
