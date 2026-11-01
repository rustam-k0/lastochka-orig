<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Пункт меню админки для справочника городов (MENDEV-11).
 * Базовый раздел — управляется через «Разделы меню» (is_visible),
 * без projectModule-тоггла (по образцу разделов FAQ и «Работа в компании»).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Идемпотентность: если запись уже существует — ничего не делаем.
        $alreadyExists = DB::table('menu_items')->where('name', 'cities')->exists();
        if ($alreadyExists) {
            return;
        }

        // Встаём в конец списка, чтобы не сдвигать существующие пункты.
        $position = (int) DB::table('menu_items')->max('position') + 1;

        DB::table('menu_items')->insert([
            'name'       => 'cities',
            'title'      => 'Города',
            'icon'       => 'map-pin-line',
            'position'   => $position,
            'is_visible' => 1,
            'accesses'   => json_encode(['admin']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('menu_items')->where('name', 'cities')->delete();
    }
};
