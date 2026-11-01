<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Магазин по умолчанию — не более одного на город (MENDEV-10).
 *
 * Единственность обеспечивается на уровне приложения (ShopService::syncDefaultShop):
 * уникальный индекс по (city_id, is_default) в MySQL не подходит, так как
 * несколько магазинов города с is_default = 0 конфликтовали бы между собой.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('shops', 'is_default')) {
            return;
        }

        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('city_id')
                ->comment('Магазин по умолчанию в своём городе');
            $table->index(['city_id', 'is_default'], 'shops_city_id_is_default_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('shops', 'is_default')) {
            return;
        }

        Schema::table('shops', function (Blueprint $table) {
            $table->dropIndex('shops_city_id_is_default_index');
            $table->dropColumn('is_default');
        });
    }
};
