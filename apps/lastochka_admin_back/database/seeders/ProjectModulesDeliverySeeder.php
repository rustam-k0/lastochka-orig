<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProjectModulesDeliverySeeder extends Seeder
{
    public function run(): void
    {
        $row = DB::table('admin_settings')->where('key', 'project_modules')->first();
        $modules = $row && is_string($row->value)
            ? (json_decode((string) $row->value, true) ?? [])
            : [];

        $modules['delivery_cdek']         ??= [
            'label'   => 'Доставка СДЭК (ПВЗ)',
            'enabled' => false,
        ];
        $modules['delivery_russian_post'] ??= [
            'label'   => 'Доставка Почтой России',
            'enabled' => false,
        ];

        $encoded = json_encode($modules, JSON_UNESCAPED_UNICODE);

        if ($row) {
            DB::table('admin_settings')
                ->where('key', 'project_modules')
                ->update(['value' => $encoded, 'updated_at' => now()]);
        } else {
            DB::table('admin_settings')->insert([
                'key'        => 'project_modules',
                'value'      => $encoded,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
