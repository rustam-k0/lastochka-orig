<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('shops', 'assembly_price')) {
            return;
        }

        Schema::table('shops', function (Blueprint $table) {
            // Плоская стоимость сборки заказа в РУБЛЯХ (модуль assembly_cost).
            // null = не задана, сборка на чекауте не взимается.
            $table->unsignedInteger('assembly_price')->nullable()->after('heavy_order_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('assembly_price');
        });
    }
};
