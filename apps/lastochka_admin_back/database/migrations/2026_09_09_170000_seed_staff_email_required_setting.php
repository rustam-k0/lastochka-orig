<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MID-293: тоггл «Почта сотрудника обязательна» (Настройки → Общие → Сотрудники).
 * Значение по умолчанию '0' — на форках, где сотрудники заводятся без почты,
 * поведение не меняется; на стенде флаг включают через админку.
 */
return new class extends Migration {
    private const KEY = 'staff_email_required';

    public function up(): void
    {
        if (DB::table('admin_settings')->where('key', self::KEY)->exists()) {
            return;
        }

        DB::table('admin_settings')->insert([
            'key'        => self::KEY,
            'value'      => '0',
            'type'       => 'boolean',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('admin_settings')->where('key', self::KEY)->delete();
    }
};
