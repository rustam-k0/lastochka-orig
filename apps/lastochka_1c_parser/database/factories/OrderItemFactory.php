<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        $price = $this->faker->randomFloat(2, 100, 10000);
        $quantity = $this->faker->numberBetween(1, 10);

        return [
            'order_id' => Order::factory(),
            'product_id' => null,
            'external_id' => $this->faker->uuid(),
            'name' => $this->faker->words(3, true),
            'quantity' => $quantity,
            'price' => $price,
            'total' => $price * $quantity,
        ];
    }

    public function withProduct(?Product $product = null): static
    {
        return $this->state(function (array $attributes) use ($product) {
            $product = $product ?? Product::factory()->create();
            
            return [
                'product_id' => $product->id,
                'external_id' => $product->external_id,
                'name' => $product->name,
            ];
        });
    }
}
