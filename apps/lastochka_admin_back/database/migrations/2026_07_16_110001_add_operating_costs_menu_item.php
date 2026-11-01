<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (DB::table('menu_items')->where('name', 'operating-costs')->exists()) {
            return;
        }

        $position = (int) DB::table('menu_items')->max('position') + 1;

        DB::table('menu_items')->insert([
            'name' => 'operating-costs',
            'title' => 'Операционные расходы',
            'icon' => 'wallet-3-line',
            'position' => $position,
            'is_visible' => 1,
            'accesses' => json_encode(['admin']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('menu_items')->where('name', 'operating-costs')->delete();
    }
};
