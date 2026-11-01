<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Название сервиса для текстов SMS.
 *
 * Операторы связи блокируют SMS с кодом или паролем, если в тексте нет
 * названия сервиса. Заводим настройку заранее и предзаполняем её названием
 * проекта (`admin_title`), чтобы после деплоя тексты уже были корректными.
 */
return new class extends Migration
{
    private const KEY = 'sms_service_name';

    public function up(): void
    {
        $exists = DB::table('admin_settings')->where('key', self::KEY)->exists();

        if ($exists) {
            return;
        }

        // Предзаполняем названием проекта; если его нет — настройку не заводим,
        // чтобы в админке было пустое поле с подсказкой, а не мусорное значение.
        $default = DB::table('admin_settings')->where('key', 'admin_title')->value('value');

        if (blank($default)) {
            return;
        }

        DB::table('admin_settings')->insert([
            'key' => self::KEY,
            'value' => $default,
            'type' => 'string',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('admin_settings')->where('key', self::KEY)->delete();
    }
};
