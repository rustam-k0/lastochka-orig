<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * orders.assembly_cost — стоимость сборки заказа (₽, модуль assembly_cost),
     * снапшот из storefront на момент создания заказа.
     *
     * Без этой колонки пересчёты суммы после сборки (AssemblyFinish в delivery_back:
     * amount = items − discount − bonus + delivery_price) теряли стоимость сборки,
     * и capture в YooKassa списывал меньше захолдированного (недосбор у мерчанта).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'assembly_cost')) {
                $table->unsignedInteger('assembly_cost')->default(0)->after('delivery_price')
                    ->comment('Стоимость сборки заказа (₽, модуль assembly_cost), снапшот из storefront');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'assembly_cost')) {
                $table->dropColumn('assembly_cost');
            }
        });
    }
};
