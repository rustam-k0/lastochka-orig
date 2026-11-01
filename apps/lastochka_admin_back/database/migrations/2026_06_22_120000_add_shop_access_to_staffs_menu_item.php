<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Открывает раздел «Сотрудники» партнёру-селлеру (роль shop):
 * добавляет "shop" в accesses пункта меню staffs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('menu_items')) {
            return;
        }

        $row = DB::table('menu_items')->where('name', 'staffs')->first();
        if (!$row) {
            return;
        }

        $accesses = json_decode($row->accesses ?? '[]', true) ?: [];
        if (in_array('shop', $accesses, true)) {
            return;
        }

        $accesses[] = 'shop';

        DB::table('menu_items')
            ->where('name', 'staffs')
            ->update([
                'accesses'   => json_encode(array_values($accesses)),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('menu_items')) {
            return;
        }

        $row = DB::table('menu_items')->where('name', 'staffs')->first();
        if (!$row) {
            return;
        }

        $accesses = json_decode($row->accesses ?? '[]', true) ?: [];
        $accesses = array_values(array_filter($accesses, fn($a) => $a !== 'shop'));

        DB::table('menu_items')
            ->where('name', 'staffs')
            ->update([
                'accesses'   => json_encode($accesses),
                'updated_at' => now(),
            ]);
    }
};
