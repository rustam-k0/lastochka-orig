<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Пункт меню админки для справочника «Причины уточнения».
     * Базовый раздел — управляется через «Разделы меню» (is_visible),
     * без projectModule-тоггла.
     */
    public function up(): void
    {
        // Идемпотентность: если запись уже существует — ничего не делаем.
        $alreadyExists = DB::table('menu_items')->where('name', 'explain-reasons')->exists();
        if ($alreadyExists) {
            return;
        }

        // Встаём в конец списка, чтобы не сдвигать существующие пункты.
        $position = (int) DB::table('menu_items')->max('position') + 1;

        DB::table('menu_items')->insert([
            'name'       => 'explain-reasons',
            'title'      => 'Причины уточнения',
            'icon'       => 'question-line',
            'position'   => $position,
            'is_visible' => 1,
            'accesses'   => json_encode(['admin']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('menu_items')->where('name', 'explain-reasons')->delete();
    }
};
