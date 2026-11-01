<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Enums\ShippingMethod;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
final class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->numberBetween(500, 5000);
        $discount = 0;
        $deliveryCost = fake()->randomElement([0, 200, 300]);
        $total = $subtotal - $discount + $deliveryCost;

        return [
            'user_id' => User::factory(),
            'store_id' => Store::factory(),
            'source' => 'storefront',
            'status' => OrderStatus::Pending,
            'payment_type' => PaymentType::Cash,
            'is_paid' => false,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'delivery_cost' => $deliveryCost,
            'total' => $total,
            'items_count' => fake()->numberBetween(1, 5),
            'delivery_slot_start' => now()->addDay()->setHour(10)->setMinute(0),
            'delivery_slot_end' => now()->addDay()->setHour(12)->setMinute(0),
            'delivery_slot_display' => '10:00-12:00',
            'shipping_method' => ShippingMethod::Courier,
            'comment' => fake()->optional(0.3)->sentence(),
        ];
    }

    /**
     * Заказ со статусом pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Pending,
        ]);
    }

    /**
     * Заказ со статусом assembling.
     */
    public function assembling(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Assembling,
        ]);
    }

    /**
     * Заказ со статусом assembled.
     */
    public function assembled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Assembled,
        ]);
    }

    /**
     * Заказ со статусом inDelivery.
     */
    public function inDelivery(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::InDelivery,
        ]);
    }

    /**
     * Заказ доставлен.
     */
    public function delivered(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Delivered,
            'is_paid' => true,
        ]);
    }

    /**
     * Заказ отменён.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Cancelled,
        ]);
    }

    /**
     * Оплаченный заказ.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_paid' => true,
        ]);
    }
}
