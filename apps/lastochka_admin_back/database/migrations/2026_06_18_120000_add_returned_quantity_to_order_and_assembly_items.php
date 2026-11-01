<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Возврат товара курьером при доставке (tg-14265).
     * returned_quantity — возвращённые «порции» (штучный — штуки; весовой —
     * полная порция round(actual_weight/step) или 0). return_reason_id —
     * причина возврата (справочник product_cancel_reasons), опционально.
     * Пишется в delivery при OrderItemReturnByCourier сразу в обе таблицы
     * (общая БД cron_admin), читается админкой/событием на DeliveryFinish.
     */
    public function up(): void
    {
        if (Schema::hasTable('order_items') && !Schema::hasColumn('order_items', 'returned_quantity')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->integer('returned_quantity')->nullable()->default(0)->after('quantity');
                $table->foreignId('return_reason_id')
                    ->nullable()
                    ->after('returned_quantity')
                    ->constrained('product_cancel_reasons')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('assembly_items') && !Schema::hasColumn('assembly_items', 'returned_quantity')) {
            Schema::table('assembly_items', function (Blueprint $table) {
                $table->integer('returned_quantity')->nullable()->default(0)->after('quantity');
                $table->foreignId('return_reason_id')
                    ->nullable()
                    ->after('returned_quantity')
                    ->constrained('product_cancel_reasons')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('order_items', 'return_reason_id')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->dropConstrainedForeignId('return_reason_id');
            });
        }
        if (Schema::hasColumn('order_items', 'returned_quantity')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->dropColumn('returned_quantity');
            });
        }
        if (Schema::hasColumn('assembly_items', 'return_reason_id')) {
            Schema::table('assembly_items', function (Blueprint $table) {
                $table->dropConstrainedForeignId('return_reason_id');
            });
        }
        if (Schema::hasColumn('assembly_items', 'returned_quantity')) {
            Schema::table('assembly_items', function (Blueprint $table) {
                $table->dropColumn('returned_quantity');
            });
        }
    }
};
