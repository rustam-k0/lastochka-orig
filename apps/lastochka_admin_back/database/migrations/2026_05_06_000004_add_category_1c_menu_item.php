<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('menu_items')) {
            return;
        }

        $alreadyExists = DB::table('menu_items')->where('name', 'category-1c')->exists();
        if ($alreadyExists) {
            return;
        }

        $position = 75;

        $occupied = DB::table('menu_items')->where('position', $position)->exists();
        if ($occupied) {
            DB::table('menu_items')
                ->where('position', '>=', $position)
                ->update(['position' => DB::raw('position + 1')]);
        }

        DB::table('menu_items')->insert([
            'name'       => 'category-1c',
            'title'      => 'Категории 1С',
            'icon'       => 'folder-line',
            'position'   => $position,
            'is_visible' => 0,
            'accesses'   => json_encode(['admin']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('menu_items')) {
            return;
        }

        DB::table('menu_items')->where('name', 'category-1c')->delete();
    }
};
