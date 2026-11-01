<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('shop_zones', 'delivery_days')) {
            return;
        }

        Schema::table('shop_zones', function (Blueprint $table) {
            // Дни недели доставки в зоне (ISO 1=Пн … 7=Вс).
            // NULL = доставка во все дни недели.
            $table->json('delivery_days')->nullable()->after('delivery_type');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('shop_zones', 'delivery_days')) {
            return;
        }

        Schema::table('shop_zones', function (Blueprint $table) {
            $table->dropColumn('delivery_days');
        });
    }
};
