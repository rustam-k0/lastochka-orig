<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MID-285: момент холдирования средств клиента по заказу.
     *
     * До этой колонки факт холда фиксировался только суммой (held_amount) и
     * строкой payment_transactions, а время приходилось восстанавливать по
     * updated_at транзакции — метке, которую перезаписывает любое следующее
     * обновление строки. В режиме «оформление без слотов» от этого момента
     * отсчитывается окно доставки, которое видят сборщик и курьер, поэтому
     * метка нужна отдельным неизменяемым полем и уезжает в курьерку событием.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'payment_held_at')) {
                $table->timestamp('payment_held_at')->nullable()->after('held_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'payment_held_at')) {
                $table->dropColumn('payment_held_at');
            }
        });
    }
};
