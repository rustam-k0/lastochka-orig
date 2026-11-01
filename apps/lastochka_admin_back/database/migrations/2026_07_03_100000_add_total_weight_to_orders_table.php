<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'total_weight')) {
                $table->unsignedInteger('total_weight')->nullable()->after('amount')
                    ->comment('Суммарный вес заказа в граммах, зафиксирован при завершении сборки');
            }
            if (!Schema::hasColumn('orders', 'is_weight_partial')) {
                $table->boolean('is_weight_partial')->default(false)->after('total_weight')
                    ->comment('Вес неполный: есть штучные позиции без веса в карточке товара');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'is_weight_partial')) {
                $table->dropColumn('is_weight_partial');
            }
            if (Schema::hasColumn('orders', 'total_weight')) {
                $table->dropColumn('total_weight');
            }
        });
    }
};
