<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Флаг сетевого каталога в project_modules (network_catalog).
 * boolean-модуль, default enabled=false (fail-safe для форков).
 * Backend читает data_get($json, 'network_catalog.enabled', false)
 * (см. App\NetworkCatalog\Support\NetworkCatalogMode).
 */
return new class extends Migration
{
    private const MODULE_KEY = 'network_catalog';

    public function up(): void
    {
        $existing = DB::table('admin_settings')->where('key', 'project_modules')->value('value');
        $modules = $existing ? (json_decode($existing, true) ?: []) : [];

        if (array_key_exists(self::MODULE_KEY, $modules)) {
            return;
        }

        $modules[self::MODULE_KEY] = [
            'label'       => 'Сетевой каталог (одна карточка товара, цены и остатки по магазинам)',
            'enabled'     => false,
            'description' => 'Режим торговой сети с единой 1С: карточка товара одна на всю сеть (дедупликация по GUID), цены и остатки хранятся пер-магазин. При выключенном флаге поведение платформы не меняется.',
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
