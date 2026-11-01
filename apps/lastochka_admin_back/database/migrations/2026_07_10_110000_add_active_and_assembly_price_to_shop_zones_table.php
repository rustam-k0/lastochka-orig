<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shop_zones', function (Blueprint $table) {
            // Флаг активности зоны. Неактивная зона не участвует в расчёте
            // условий доставки на сторефронте. Default true — существующие
            // зоны остаются включёнными.
            if (!Schema::hasColumn('shop_zones', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('delivery_type');
            }

            // Стоимость сборки заказа для зоны. NULL = не задана.
            if (!Schema::hasColumn('shop_zones', 'assembly_price')) {
                $table->unsignedInteger('assembly_price')->nullable()->after('is_active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shop_zones', function (Blueprint $table) {
            if (Schema::hasColumn('shop_zones', 'assembly_price')) {
                $table->dropColumn('assembly_price');
            }

            if (Schema::hasColumn('shop_zones', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });
    }
};
