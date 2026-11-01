<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Флаг «Диапазоны стоимости доставки» в project_modules (delivery_price_ranges, MID-280).
 * boolean-модуль, default enabled=false (fail-safe для форков).
 * Backend читает ModuleConfigService::isDeliveryPriceRangesEnabled().
 */
return new class extends Migration
{
    private const MODULE_KEY = 'delivery_price_ranges';

    public function up(): void
    {
        $existing = DB::table('admin_settings')->where('key', 'project_modules')->value('value');
        $modules = $existing ? (json_decode($existing, true) ?: []) : [];

        if (array_key_exists(self::MODULE_KEY, $modules)) {
            return;
        }

        $modules[self::MODULE_KEY] = [
            'label'       => 'Диапазоны стоимости доставки',
            'enabled'     => false,
            'description' => 'Стоимость доставки зависит от суммы заказа: у зоны задаются диапазоны «от суммы: цена».',
        ];

        $value = json_encode($modules, JSON_UNESCAPED_UNICODE);
        $exists = DB::table('admin_settings')->where('key', 'project_modules')->exists();

        if ($exists) {
            DB::table('admin_settings')->where('key', 'project_modules')->update([
                'value' => $value, 'type' => 'json', 'updated_at' => now(),
            ]);
        } else {
            DB::table('admin_settings')->insert([
                'key' => 'project_modules', 'value' => $value, 'type' => 'json',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $existing = DB::table('admin_settings')->where('key', 'project_modules')->value('value');
        if ($existing === null) {
            return;
        }
        $modules = json_decode($existing, true) ?: [];
        unset($modules[self::MODULE_KEY]);
        DB::table('admin_settings')->where('key', 'project_modules')->update([
            'value' => json_encode($modules, JSON_UNESCAPED_UNICODE), 'type' => 'json', 'updated_at' => now(),
        ]);
    }
};
