<?php

namespace Database\Seeders;

use App\Models\City;
use Illuminate\Database\Seeder;

class CitySeeder extends Seeder
{
    public function run(): void
    {
        $cities = [
            ['name' => 'Махачкала', 'latitude' => 42.9849, 'longitude' => 47.5047, 'is_default' => true],
            ['name' => 'Буйнакск', 'latitude' => 42.8228, 'longitude' => 47.1183],
            ['name' => 'Дагестанские Огни', 'latitude' => 42.1086, 'longitude' => 48.1928],
            ['name' => 'Дербент', 'latitude' => 42.0580, 'longitude' => 48.2901],
            ['name' => 'Избербаш', 'latitude' => 42.5642, 'longitude' => 47.8633],
            ['name' => 'Каспийск', 'latitude' => 42.8817, 'longitude' => 47.6394],
            ['name' => 'Кизилюрт', 'latitude' => 43.0167, 'longitude' => 46.8733],
            ['name' => 'Кизляр', 'latitude' => 43.8500, 'longitude' => 46.7167],
            ['name' => 'Южно-Сухокумск', 'latitude' => 44.0000, 'longitude' => 45.6833],
        ];

        foreach ($cities as $city) {
            City::firstOrCreate(
                ['name' => $city['name']],
                array_merge($city, [
                    'is_active' => true,
                    'is_default' => $city['is_default'] ?? false,
                ])
            );
        }
    }
}
