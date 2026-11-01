<?php

namespace Database\Factories;

use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShopFactory extends Factory
{
    protected $model = Shop::class;

    public function definition(): array
    {
        return [
            // В shops нет колонки name — название магазина хранится в title (NOT NULL)
            'title' => $this->faker->company(),
            'code' => $this->faker->unique()->slug(2),
            'address' => $this->faker->address(),
            'slot_time' => 60,
            'slot_max_orders' => 5,
        ];
    }
}
