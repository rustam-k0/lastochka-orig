<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Причина замены товара (опциональная) на позициях сборки и заказа.
     * Пишется в delivery при ProductConfirm с replacement_product_id сразу в обе
     * таблицы (общая БД), читается админкой на странице заказа
     * (order_items.replace_reason_id).
     */
    public function up(): void
    {
        if (Schema::hasTable('assembly_items') && !Schema::hasColumn('assembly_items', 'replace_reason_id')) {
            Schema::table('assembly_items', function (Blueprint $table) {
                $table->foreignId('replace_reason_id')
                    ->nullable()
                    ->after('cancel_reason_id')
                    ->constrained('product_replace_reasons')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('order_items') && !Schema::hasColumn('order_items', 'replace_reason_id')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->foreignId('replace_reason_id')
                    ->nullable()
                    ->after('cancel_reason_id')
                    ->constrained('product_replace_reasons')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('assembly_items', 'replace_reason_id')) {
            Schema::table('assembly_items', function (Blueprint $table) {
                $table->dropConstrainedForeignId('replace_reason_id');
            });
        }

        if (Schema::hasColumn('order_items', 'replace_reason_id')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->dropConstrainedForeignId('replace_reason_id');
            });
        }
    }
};
