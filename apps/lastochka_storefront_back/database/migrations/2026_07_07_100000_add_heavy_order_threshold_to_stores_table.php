<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // Порог «Тяжёлого заказа» в граммах, зеркало shops.heavy_order_threshold
            // (приходит по RabbitMQ из admin_back). null = порог не задан.
            $table->unsignedInteger('heavy_order_threshold')->nullable()->after('pickup_grace_period_hours');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('heavy_order_threshold');
        });
    }
};
