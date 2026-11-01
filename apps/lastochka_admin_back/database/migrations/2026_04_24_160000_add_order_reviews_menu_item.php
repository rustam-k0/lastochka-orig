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

        $alreadyExists = DB::table('menu_items')->where('name', 'order-reviews')->exists();
        if ($alreadyExists) {
            return;
        }

        $position = 15;

        $occupied = DB::table('menu_items')->where('position', $position)->exists();
        if ($occupied) {
            DB::table('menu_items')
                ->where('position', '>=', $position)
                ->update(['position' => DB::raw('position + 1')]);
        }

        DB::table('menu_items')->insert([
            'name'       => 'order-reviews',
            'title'      => 'Отзывы на доставку',
            'icon'       => 'truck-line',
            'position'   => $position,
            'is_visible' => 1,
            'accesses'   => json_encode(['admin', 'shop']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('menu_items')) {
            return;
        }

        DB::table('menu_items')->where('name', 'order-reviews')->delete();
    }
};
