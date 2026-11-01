<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Флаг «Цены от количества» в project_modules (price_tiers, MID-308).
 * boolean-модуль, default enabled=false (fail-safe для форков).
 * Backend читает ModuleConfigService::isPriceTiersEnabled().
 */
return new class extends Migration
{
    private const MODULE_KEY = 'price_tiers';

    public function up(): void
    {
        $existing = DB::table('admin_settings')->where('key', 'project_modules')->value('value');
        $modules = $existing ? (json_decode($existing, true) ?: []) : [];

        if (array_key_exists(self::MODULE_KEY, $modules)) {
            return;
        }

        $modules[self::MODULE_KEY] = [
            'label'       => 'Цены от количества',
            'enabled'     => false,
            'description' => 'Ступенчатая цена товара в зависимости от количества в позиции: лесенка «от N единиц» задаётся по магазинам из 1С или в карточке товара, применяется в корзине и заказе, пересчитывается по фактическому количеству после сборки. При выключенном флаге поведение платформы не меняется. Включает только суперадминистратор',
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
