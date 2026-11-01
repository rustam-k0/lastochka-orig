<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'is_heavy')) {
                // Признак «Тяжёлый заказ»: вес превышает порог магазина
                // (shops.heavy_order_threshold). Ставится при создании заказа
                // (событие от storefront), пересчитывается при завершении сборки.
                $table->boolean('is_heavy')->default(false)->after('is_weight_partial');
            }
            if (!Schema::hasColumn('orders', 'couriers_count')) {
                // Число курьеров, задействованных в доставке (уникальные, без
                // отменённых назначений). >1 = совместная доставка. Пишет delivery_back.
                $table->unsignedTinyInteger('couriers_count')->default(0)->after('is_heavy');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['is_heavy', 'couriers_count']);
        });
    }
};
