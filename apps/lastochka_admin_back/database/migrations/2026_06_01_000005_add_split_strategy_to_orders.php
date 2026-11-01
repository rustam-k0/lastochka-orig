<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Разметка origin'а split'а заказа (aggregator-режим, FOODLINK-10874).
 *
 * NULL (legacy) | 'zone' (marketplace) | 'shop' (aggregator). Default NULL —
 * не ломает форки и legacy-заказы (см. docs/aggregator-mode.md §3.3, §5.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('orders') || Schema::hasColumn('orders', 'split_strategy')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->string('split_strategy', 16)->nullable()->after('assembly_zone_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'split_strategy')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('split_strategy');
            });
        }
    }
};
