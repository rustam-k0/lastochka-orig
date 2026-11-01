<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DefaultValuesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Создаем заглушку для производителя
        if (Schema::hasTable('manufacturers')) {
            if (!DB::table('manufacturers')->where('id', 1)->exists()) {
                DB::table('manufacturers')->insert([
                    'id' => 1,
                    'title' => 'Без производителя',
                    'icon' => 'icon',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
        
        // Создаем заглушку для бренда
        if (Schema::hasTable('brands')) {
            if (!DB::table('brands')->where('id', 1)->exists()) {
                DB::table('brands')->insert([
                    'id' => 1,
                    'title' => 'Без бренда',
                    'categories_page' => false,
                    'is_popular' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
        
        // Создаем заглушку для страны/города
        if (Schema::hasTable('countries')) {
            if (!DB::table('countries')->where('id', 1)->exists()) {
                DB::table('countries')->insert([
                    'id' => 1,
                    'title' => 'Город не указан',
                    'icon' => 'icon',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
        
        // Создаем заглушку для категории
        if (Schema::hasTable('categories')) {
            if (!DB::table('categories')->where('id', 1)->exists()) {
                DB::table('categories')->insert([
                    'id' => 1,
                    'title' => 'Без категории',
                    'link' => '',
                    'position' => 0,
                    'important' => false,
                    'min_quantity' => 0,
                    'without_blocking_price' => false,
                    'is_cooking' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if (Schema::hasTable('shops')) {
            if (!DB::table('shops')->where('id', 1)->exists()) {
                DB::table('shops')->insert([
                    'id' => 1,
                    'title' => 'gajievabsamat',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
