<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Пункт сайдбара «Настройки приложения». Только DB::table, не Eloquent —
     * модельные события в миграциях валят CI на правах логов.
     * Позиция — в конец корневого уровня, чтобы не сдвигать существующие
     * пункты (position-сдвиги в миграциях — источник неидемпотентности).
     */
    public function up(): void
    {
        if (DB::table('menu_items')->where('name', 'app-settings')->exists()) {
            return;
        }

        $position = (int) DB::table('menu_items')->whereNull('parent_id')->max('position') + 1;

        DB::table('menu_items')->insert([
            'name' => 'app-settings',
            'title' => 'Настройки приложения',
            'icon' => 'smartphone-line',
            'position' => $position,
            'is_visible' => 1,
            'accesses' => json_encode(['admin', 'shop']),
            'is_folder' => 0,
            'parent_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('menu_items')->where('name', 'app-settings')->delete();
    }
};
