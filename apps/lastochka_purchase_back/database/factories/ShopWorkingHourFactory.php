<?php

namespace Database\Factories;

use App\Models\Shop;
use App\Models\ShopWorkingHour;
use App\Models\Type;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShopWorkingHourFactory extends Factory
{
    protected $model = ShopWorkingHour::class;

    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            'day_of_week' => $this->faker->numberBetween(1, 7),
            'opening_time' => '09:00:00',
            'closing_time' => '21:00:00',
            'type_id' => Type::WORK_SCHEDULE,
        ];
    }
}
