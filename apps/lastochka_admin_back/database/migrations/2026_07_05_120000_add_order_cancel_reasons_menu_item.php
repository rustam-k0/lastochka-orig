<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Пункт меню админки для справочника «Причины отмены заказа».
     * Базовый раздел — управляется через «Разделы меню» (is_visible),
     * без projectModule-тоггла.
     */
    public function up(): void
    {
        // Идемпотентность: если запись уже существует — ничего не делаем.
        $alreadyExists = DB::table('menu_items')->where('name', 'order-cancel-reasons')->exists();
        if ($alreadyExists) {
            return;
        }

        // Встаём в конец списка, чтобы не сдвигать существующие пункты.
        $position = (int) DB::table('menu_items')->max('position') + 1;

        DB::table('menu_items')->insert([
            'name'       => 'order-cancel-reasons',
            'title'      => 'Причины отмены заказа',
            'icon'       => 'file-list-3-line',
            'position'   => $position,
            'is_visible' => 1,
            'accesses'   => json_encode(['admin']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('menu_items')->where('name', 'order-cancel-reasons')->delete();
    }
};
