<?php

namespace Database\Factories;

use App\Models\ShopZone;
use App\Models\ZoneCoordinate;
use Illuminate\Database\Eloquent\Factories\Factory;

class ZoneCoordinateFactory extends Factory
{
    protected $model = ZoneCoordinate::class;

    public function definition(): array
    {
        return [
            'shop_zone_id' => ShopZone::factory(),
            'latitude' => $this->faker->latitude(),
            'longitude' => $this->faker->longitude(),
            'position' => $this->faker->numberBetween(1, 10),
        ];
    }
}
