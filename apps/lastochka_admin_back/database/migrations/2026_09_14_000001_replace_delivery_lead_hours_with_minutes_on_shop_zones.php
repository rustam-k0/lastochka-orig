<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Минимальное время до доставки у зоны: часы → минуты.
 *
 * Целые часы не позволяли задать, например, 30 минут. Новая колонка
 * delivery_lead_minutes (INT UNSIGNED NULL), старая delivery_lead_hours
 * переносится как hours × 60 и удаляется. NULL/0 = без задержки.
 *
 * Идемпотентна для форков: каждый шаг проверяет наличие колонок.
 * Только DB::table — Eloquent в миграциях admin_back триггерит observer и логи.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('shop_zones', 'delivery_lead_minutes')) {
            Schema::table('shop_zones', function (Blueprint $table) {
                $table->unsignedInteger('delivery_lead_minutes')->nullable()->after('delivery_days');
            });
        }

        if (Schema::hasColumn('shop_zones', 'delivery_lead_hours')) {
            DB::table('shop_zones')
                ->whereNull('delivery_lead_minutes')
                ->whereNotNull('delivery_lead_hours')
                ->where('delivery_lead_hours', '>', 0)
                ->update(['delivery_lead_minutes' => DB::raw('delivery_lead_hours * 60')]);

            Schema::table('shop_zones', function (Blueprint $table) {
                $table->dropColumn('delivery_lead_hours');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('shop_zones', 'delivery_lead_hours')) {
            Schema::table('shop_zones', function (Blueprint $table) {
                $table->unsignedInteger('delivery_lead_hours')->nullable()->after('delivery_days');
            });
        }

        if (Schema::hasColumn('shop_zones', 'delivery_lead_minutes')) {
            // Обратно — с округлением вверх, чтобы не потерять задержку меньше часа
            DB::table('shop_zones')
                ->whereNull('delivery_lead_hours')
                ->whereNotNull('delivery_lead_minutes')
                ->where('delivery_lead_minutes', '>', 0)
                ->update(['delivery_lead_hours' => DB::raw('CEIL(delivery_lead_minutes / 60)')]);

            Schema::table('shop_zones', function (Blueprint $table) {
                $table->dropColumn('delivery_lead_minutes');
            });
        }
    }
};
