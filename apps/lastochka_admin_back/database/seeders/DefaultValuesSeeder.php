<?php

namespace Database\Seeders;

use App\Models\Shop;
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
                    'show_on_main' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if (Schema::hasTable('shops')) {
            if (!DB::table('shops')->where('id', 1)->exists()) {
                $title = 'gajievabsamat';

                $row = [
                    'id' => 1,
                    'title' => $title,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                // Raw insert минует Eloquent-хук Shop::creating, который генерирует code из title.
                // Без этого у дефолтного магазина code = NULL и URL обмена 1С теряет shop_code.
                if (Schema::hasColumn('shops', 'code')) {
                    $row['code'] = Shop::generateCodeFromTitle($title);
                }

                DB::table('shops')->insert($row);
            }
        }
    }
}
