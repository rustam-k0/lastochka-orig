<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Объединение стоп-листов с полями карточки товара (Platrum 12188):
 * merge локальных флагов в is_active / pickup_only, затем удаление колонок.
 * Локальный merge обязателен: admin_back мержит свои данные прямым SQL и
 * событий не публикует; флаги здесь были синканы событиями ранее и консистентны.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'stop_list_hidden')) {
            DB::table('products')->where('stop_list_hidden', true)->update(['is_active' => false]);
        }
        if (Schema::hasColumn('products', 'stop_list_pickup_only')) {
            DB::table('products')->where('stop_list_pickup_only', true)->update(['pickup_only' => true]);
        }

        Schema::table('products', function (Blueprint $table) {
            foreach (['stop_list_hidden', 'stop_list_pickup_only'] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        // Обратный merge невозможен — восстанавливаем только структуру.
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'stop_list_hidden')) {
                $table->boolean('stop_list_hidden')->default(false)->after('pickup_only');
            }
            if (!Schema::hasColumn('products', 'stop_list_pickup_only')) {
                $table->boolean('stop_list_pickup_only')->default(false)->after('stop_list_hidden');
            }
        });
    }
};
