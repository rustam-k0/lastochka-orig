<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            // orders.shop_id — NOT NULL с multishop-миграции 2026_03_29_200003
            'shop_id' => 1,
            'shop_order_id' => $this->faker->unique()->numberBetween(1, 999999),
            'external_id' => null,
            'number' => $this->faker->unique()->numerify('##########'),
            'status' => Order::STATUS_NEW,
            'source' => Order::SOURCE_MANUAL,
            'customer_name' => $this->faker->name(),
            'customer_phone' => $this->faker->phoneNumber(),
            'customer_email' => $this->faker->safeEmail(),
            'delivery_address' => $this->faker->address(),
            'comment' => $this->faker->optional()->sentence(),
            'total' => $this->faker->randomFloat(2, 100, 50000),
            'exported_at' => null,
            'received_at' => null,
            'one_c_response' => null,
        ];
    }

    public function fromShop(): static
    {
        return $this->state(fn(array $attributes) => [
            'source' => Order::SOURCE_SHOP,
            'received_at' => now(),
        ]);
    }

    public function exported(): static
    {
        return $this->state(fn(array $attributes) => [
            'exported_at' => now(),
            'external_id' => $this->faker->uuid(),
        ]);
    }

    public function forExport(): static
    {
        return $this->state(fn(array $attributes) => [
            'status' => Order::STATUS_NEW,
            'exported_at' => null,
        ]);
    }
}
