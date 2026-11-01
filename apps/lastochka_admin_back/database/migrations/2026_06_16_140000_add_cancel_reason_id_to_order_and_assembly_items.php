<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Причина отмены товара (опциональная) на позициях сборки и заказа.
     * Пишется в delivery при ProductCancel сразу в обе таблицы (общая БД),
     * читается админкой на странице заказа (order_items.cancel_reason_id).
     */
    public function up(): void
    {
        if (Schema::hasTable('assembly_items') && !Schema::hasColumn('assembly_items', 'cancel_reason_id')) {
            Schema::table('assembly_items', function (Blueprint $table) {
                $table->foreignId('cancel_reason_id')
                    ->nullable()
                    ->after('explain_reason_id')
                    ->constrained('product_cancel_reasons')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('order_items') && !Schema::hasColumn('order_items', 'cancel_reason_id')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->foreignId('cancel_reason_id')
                    ->nullable()
                    ->after('status_id')
                    ->constrained('product_cancel_reasons')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('assembly_items', 'cancel_reason_id')) {
            Schema::table('assembly_items', function (Blueprint $table) {
                $table->dropConstrainedForeignId('cancel_reason_id');
            });
        }

        if (Schema::hasColumn('order_items', 'cancel_reason_id')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->dropConstrainedForeignId('cancel_reason_id');
            });
        }
    }
};
