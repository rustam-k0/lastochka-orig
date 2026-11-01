<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Флаг режима агрегатора в project_modules (aggregator-режим, FOODLINK-10874).
 *
 * boolean-модуль aggregator_enabled, default enabled=false (fail-safe для форков).
 * Backend читает data_get($json, 'aggregator_enabled.enabled', false)
 * (см. App\Aggregator\Support\AggregatorMode). Пишем через DB-builder (не Eloquent),
 * как остальные module-миграции — чтобы не триггерить RabbitMQ-observer на CI.
 */
return new class extends Migration
{
    private const MODULE_KEY = 'aggregator_enabled';

    public function up(): void
    {
        $existing = DB::table('admin_settings')->where('key', 'project_modules')->value('value');
        $modules = $existing ? (json_decode($existing, true) ?: []) : [];

        // Идемпотентно: если ключ уже есть — не трогаем (админ мог включить вручную).
        if (array_key_exists(self::MODULE_KEY, $modules)) {
            return;
        }

        $modules[self::MODULE_KEY] = [
            'label'       => 'Режим агрегатора (Платформенные товары + офферы)',
            'enabled'     => false,
            'description' => 'Дедупликация товаров по штрихкоду: один товар на платформе, N магазинов-поставщиков с собственными ценами и остатками. При выключенном флаге aggregator-код не выполняется (импортёр офферов, сплиттер заказов по магазинам, публикация offer-событий в RabbitMQ, разделы «Платформенные товары» и «Карантин» в сайдбаре).',
        ];

        $value = json_encode($modules, JSON_UNESCAPED_UNICODE);

        $exists = DB::table('admin_settings')->where('key', 'project_modules')->exists();

        if ($exists) {
            DB::table('admin_settings')
                ->where('key', 'project_modules')
                ->update([
                    'value'      => $value,
                    'type'       => 'json',
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('admin_settings')->insert([
                'key'        => 'project_modules',
                'value'      => $value,
                'type'       => 'json',
                'created_at' => now(),
                'updated_at' => now(),
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

        DB::table('admin_settings')
            ->where('key', 'project_modules')
            ->update([
                'value'      => json_encode($modules, JSON_UNESCAPED_UNICODE),
                'type'       => 'json',
                'updated_at' => now(),
            ]);
    }
};
