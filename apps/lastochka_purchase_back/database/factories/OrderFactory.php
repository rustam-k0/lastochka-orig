<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Order;
use App\Models\Shop;
use App\Models\Type;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $slotStart = $this->faker->dateTimeBetween('now', '+7 days');
        $slotEnd = (clone $slotStart)->modify('+1 hour');

        return [
            'uuid' => Str::uuid(),
            'shop_id' => Shop::factory(),
            // orders.client_id и payment_type_id — NOT NULL без default (FK на clients)
            'client_id' => Client::factory(),
            'payment_type_id' => Type::PAYMENT_CASH,
            'type_id' => Type::RECEIVE_DELIVERY,
            'status_id' => 1,
            'amount' => $this->faker->numberBetween(500, 5000),
            'origin_amount' => $this->faker->numberBetween(500, 5000),
            'slot_start_date' => $slotStart,
            'slot_end_date' => $slotEnd,
        ];
    }
}
