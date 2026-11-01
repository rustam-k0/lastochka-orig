<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Магазин по умолчанию в городе (MENDEV-10).
 *
 * Источник истины — курьерка: флаг приезжает в событии магазина
 * (ShopEventTransformer), витрина его только отображает.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('stores', 'is_default')) {
            return;
        }

        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('city_id');
            $table->index(['city_id', 'is_default']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('stores', 'is_default')) {
            return;
        }

        Schema::table('stores', function (Blueprint $table) {
            $table->dropIndex(['city_id', 'is_default']);
            $table->dropColumn('is_default');
        });
    }
};
