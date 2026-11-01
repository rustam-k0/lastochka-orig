<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Пункты сайдбара для aggregator-режима (FOODLINK-10874): «Платформенные товары»
 * и «Карантин». Видимость гейтится фронтом по moduleKey=aggregator_enabled
 * (AsideComponent.getModuleConfig). is_visible=0 — по умолчанию скрыты.
 */
return new class extends Migration
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private const ITEMS = [
        [
            'name'     => 'platform-products',
            'title'    => 'Платформенные товары',
            'icon'     => 'box-line',
            'position' => 76,
        ],
        [
            'name'     => 'aggregator-quarantine',
            'title'    => 'Карантин',
            'icon'     => 'alert-line',
            'position' => 77,
        ],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('menu_items')) {
            return;
        }

        foreach (self::ITEMS as $item) {
            $alreadyExists = DB::table('menu_items')->where('name', $item['name'])->exists();
            if ($alreadyExists) {
                continue;
            }

            $position = $item['position'];
            $occupied = DB::table('menu_items')->where('position', $position)->exists();
            if ($occupied) {
                DB::table('menu_items')
                    ->where('position', '>=', $position)
                    ->update(['position' => DB::raw('position + 1')]);
            }

            DB::table('menu_items')->insert([
                'name'       => $item['name'],
                'title'      => $item['title'],
                'icon'       => $item['icon'],
                'position'   => $position,
                'is_visible' => 1,
                'accesses'   => json_encode(['admin']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('menu_items')) {
            return;
        }

        DB::table('menu_items')
            ->whereIn('name', array_column(self::ITEMS, 'name'))
            ->delete();
    }
};
