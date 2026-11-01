<?php

namespace Database\Factories;

use App\Models\Shop;
use App\Models\Type;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShopFactory extends Factory
{
  protected $model = Shop::class;

  public function definition(): array
  {
    return [
      'phone' => $this->faker->phoneNumber(),
      'email' => $this->faker->unique()->safeEmail(),
      'slot_time' => 30,
      'slot_max_orders' => 10,
      'longitude' => $this->faker->longitude(),
      'latitude' => $this->faker->latitude(),
      'address' => $this->faker->address(),
      'payment_type_id' => Type::PAYMENT_CASH,
      'commission' => 0,
      'deposit' => 0,
      'type_id' => Type::DELIVERY_COURIER,
      'description' => $this->faker->text(),
    ];
  }
}
