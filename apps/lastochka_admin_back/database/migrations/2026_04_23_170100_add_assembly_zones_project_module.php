<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MODULE_KEY = 'assembly_zones';

    public function up(): void
    {
        $existing = DB::table('admin_settings')->where('key', 'project_modules')->value('value');
        $modules = $existing ? (json_decode($existing, true) ?: []) : [];

        // Идемпотентно: если ключ уже есть — ничего не трогаем (у админа может быть
        // выставлено enabled=true вручную, не хотим это переписывать).
        if (array_key_exists(self::MODULE_KEY, $modules)) {
            return;
        }

        $modules[self::MODULE_KEY] = [
            'label'       => 'Разделение заказов на подзаказы по зонам сборки',
            'enabled'     => false,
            'description' => 'Автоматически расщепляет заказ на подзаказы по зонам сборки товаров, если у категорий проставлена зона. При выключенном модуле поле «Зона сборки» в категориях и раздел «Зоны сборки» в сайдбаре скрываются, splitOrderByZones не триггерится.',
        ];

        $value = json_encode($modules, JSON_UNESCAPED_UNICODE);

        // Пишем через DB-builder напрямую, минуя Eloquent. Это нужно чтобы:
        // 1) не триггерить RabbitMQ-observer (его лог падает на CI с `Permission denied`),
        // 2) не ломать миграции зависимостью от конкретной модели AdminSetting.
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
