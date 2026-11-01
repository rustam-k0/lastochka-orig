<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ступени стоимости доставки зоны от суммы заказа (MID-280).
 *
 * JSON [{from_amount, price}] по возрастанию from_amount, целые рубли: от суммы
 * товаров from_amount (до промокода и бонусов) доставка стоит price, ниже первой
 * ступени действует delivery_price зоны, price = 0 означает бесплатную доставку.
 * NULL или [] — ступеней нет. free_delivery_from остаётся колонкой и вычисляется
 * из ступеней при сохранении зоны (ступень с ценой 0).
 *
 * Перенос данных: зона с порогом free_delivery_from получает одну бесплатную
 * ступень {from_amount: порог, price: 0}, для клиента ничего не меняется.
 * Перенос идемпотентен (трогает только зоны без ступеней) и выполняется, даже
 * если колонку уже создал другой форк или ветка.
 *
 * Общая БД курьерских модулей: колонку читают пакет slots-api (purchase_back)
 * и delivery_back.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shop_zones', 'delivery_price_ranges')) {
            Schema::table('shop_zones', function (Blueprint $table) {
                $table->json('delivery_price_ranges')->nullable()->after('free_delivery_from')
                    ->comment('Ступени стоимости доставки от суммы заказа (MID-280); NULL — ступеней нет');
            });
        }

        DB::table('shop_zones')
            ->whereNotNull('free_delivery_from')
            ->where('free_delivery_from', '>=', 0)
            ->whereNull('delivery_price_ranges')
            ->orderBy('id')
            ->get(['id', 'free_delivery_from'])
            ->each(function ($zone) {
                DB::table('shop_zones')->where('id', $zone->id)->update([
                    'delivery_price_ranges' => json_encode([
                        ['from_amount' => (int) $zone->free_delivery_from, 'price' => 0],
                    ]),
                ]);
            });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('shop_zones', 'delivery_price_ranges')) {
            return;
        }

        Schema::table('shop_zones', function (Blueprint $table) {
            $table->dropColumn('delivery_price_ranges');
        });
    }
};
