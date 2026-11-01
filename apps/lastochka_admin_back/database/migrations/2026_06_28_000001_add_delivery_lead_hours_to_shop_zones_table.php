<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('shop_zones', 'delivery_lead_hours')) {
            return;
        }

        Schema::table('shop_zones', function (Blueprint $table) {
            // Минимальное время до доставки в часах (lead time от «сейчас»).
            // Слот предлагается только если его старт не раньше, чем через N часов
            // от текущего момента. NULL = без дополнительной задержки.
            $table->unsignedInteger('delivery_lead_hours')->nullable()->after('delivery_days');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('shop_zones', 'delivery_lead_hours')) {
            return;
        }

        Schema::table('shop_zones', function (Blueprint $table) {
            $table->dropColumn('delivery_lead_hours');
        });
    }
};
