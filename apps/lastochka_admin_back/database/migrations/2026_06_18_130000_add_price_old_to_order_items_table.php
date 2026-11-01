<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Оригинальная цена позиции ДО скидки (приходит из storefront в событии
 * заказа как unit_price_old). Нужна, чтобы показать зачёркнутую цену в
 * админке. null — если у товара не было скидки. Тип float — как price /
 * price_during_order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->float('price_old')->nullable()->after('price_during_order');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('price_old');
        });
    }
};
