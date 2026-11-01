<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Снапшот лесенки «цены от количества» в позиции заказа (MID-308).
 *
 * JSON вида {base_price, base_price_old, base_is_eligible, tiers: [{min_quantity,
 * price}]}, всё в порциях и ценах за порцию. Строит тот, кто создаёт позицию
 * (витрина при оформлении, delivery_back при добавлении сборщиком); по нему
 * любой сервис пересчитывает цену позиции при смене количества, не обращаясь
 * к живым ценам товара. NULL — у позиции лесенки нет, формулы прежние.
 *
 * Общая БД курьерских модулей: колонку читают и пишут delivery_back и
 * purchase_back. Guard — колонку мог создать другой форк/ветка.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('order_items', 'price_tiers')) {
            return;
        }

        Schema::table('order_items', function (Blueprint $table) {
            $table->json('price_tiers')->nullable()->after('price_old')
                ->comment('Снапшот лесенки цен от количества (MID-308); NULL — лесенки нет');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('order_items', 'price_tiers')) {
            return;
        }

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('price_tiers');
        });
    }
};
