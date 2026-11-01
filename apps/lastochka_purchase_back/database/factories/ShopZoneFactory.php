<?php

namespace Database\Factories;

use App\Models\Shop;
use App\Models\ShopZone;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShopZoneFactory extends Factory
{
    protected $model = ShopZone::class;

    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            'delivery_price' => $this->faker->numberBetween(100, 500),
            'min_order_price' => $this->faker->numberBetween(300, 1000),
        ];
    }
}
