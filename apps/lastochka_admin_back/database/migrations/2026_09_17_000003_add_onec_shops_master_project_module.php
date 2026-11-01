<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Флаг «1С ведёт магазины и сотрудников» в project_modules (onec_shops_master, MID-306).
 * boolean-модуль, default enabled=false (fail-safe для форков).
 * Backend читает ModuleConfigService::isOneCShopsMasterEnabled().
 */
return new class extends Migration
{
    private const MODULE_KEY = 'onec_shops_master';

    public function up(): void
    {
        $existing = DB::table('admin_settings')->where('key', 'project_modules')->value('value');
        $modules = $existing ? (json_decode($existing, true) ?: []) : [];

        if (array_key_exists(self::MODULE_KEY, $modules)) {
            return;
        }

        $modules[self::MODULE_KEY] = [
            'label'       => '1С ведёт магазины и сотрудников',
            'enabled'     => false,
            'description' => 'Только для прямой публикации 1С в RabbitMQ. Данные магазинов из 1С перезаписывают статус, контакты, координаты, слот, график и порог тяжёлого заказа; принимаются сотрудники и их графики. При выключенном флаге поведение платформы не меняется.',
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
