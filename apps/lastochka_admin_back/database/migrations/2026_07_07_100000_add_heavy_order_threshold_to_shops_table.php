<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('shops', 'heavy_order_threshold')) {
            return;
        }

        Schema::table('shops', function (Blueprint $table) {
            // Порог «Тяжёлого заказа» в ГРАММАХ (админ вводит кг, фронт конвертирует ×1000).
            // null = порог не задан, заказы магазина не помечаются тяжёлыми.
            $table->unsignedInteger('heavy_order_threshold')->nullable()->after('pickup_grace_period_hours');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('heavy_order_threshold');
        });
    }
};
