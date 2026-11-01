<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Стоимость сборки заказа в рублях, взятая из stores.assembly_price
            // в момент создания заказа (модуль assembly_cost). Входит в total.
            $table->unsignedInteger('assembly_cost')->default(0)->after('delivery_cost');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('assembly_cost');
        });
    }
};
