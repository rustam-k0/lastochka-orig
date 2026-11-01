<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Идемпотентность: если запись уже существует (вставили вручную до миграции
        // или повторно прогоняем миграцию) — ничего не делаем.
        $alreadyExists = DB::table('menu_items')->where('name', 'assembly-zones')->exists();
        if ($alreadyExists) {
            return;
        }

        // Сдвигаем все пункты, которые стоят на позиции >= 5, на +1,
        // чтобы освободить слот под новый пункт. Только на первом прогоне.
        DB::table('menu_items')
            ->where('position', '>=', 5)
            ->update(['position' => DB::raw('position + 1')]);

        DB::table('menu_items')->insert([
            'name'       => 'assembly-zones',
            'title'      => 'Зоны сборки',
            'icon'       => 'map-pin-line',
            'position'   => 5,
            'is_visible' => 1,
            'accesses'   => json_encode(['admin']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $existed = DB::table('menu_items')->where('name', 'assembly-zones')->exists();
        if (!$existed) {
            return;
        }

        DB::table('menu_items')->where('name', 'assembly-zones')->delete();

        DB::table('menu_items')
            ->where('position', '>=', 6)
            ->update(['position' => DB::raw('position - 1')]);
    }
};
