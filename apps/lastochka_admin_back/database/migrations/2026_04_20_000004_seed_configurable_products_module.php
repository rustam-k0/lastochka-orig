<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('admin_settings')) {
            return;
        }

        $row = DB::table('admin_settings')->where('key', 'project_modules')->first();

        $modules = [];
        if ($row && $row->value) {
            $decoded = json_decode((string) $row->value, true);
            if (is_array($decoded)) {
                $modules = $decoded;
            }
        }

        if (isset($modules['configurable_products'])) {
            return;
        }

        $modules['configurable_products'] = [
            'label' => 'Вариативные товары',
            'enabled' => false,
            'description' => 'Товары с выбором вариаций (цвет, размер) на витрине',
        ];

        $encoded = json_encode($modules, JSON_UNESCAPED_UNICODE);

        if ($row) {
            DB::table('admin_settings')
                ->where('key', 'project_modules')
                ->update([
                    'value' => $encoded,
                    'type' => 'json',
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('admin_settings')->insert([
                'key' => 'project_modules',
                'value' => $encoded,
                'type' => 'json',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('admin_settings')) {
            return;
        }

        $row = DB::table('admin_settings')->where('key', 'project_modules')->first();
        if (!$row) {
            return;
        }

        $decoded = json_decode((string) $row->value, true);
        if (!is_array($decoded)) {
            return;
        }

        unset($decoded['configurable_products']);

        DB::table('admin_settings')
            ->where('key', 'project_modules')
            ->update([
                'value' => json_encode($decoded, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
    }
};
