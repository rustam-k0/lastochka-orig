<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Модуль collector_payment: заказ «ожидает доплату» после неуспешного
     * автосписания разницы (итог сборки > холда). surcharge_amount — сумма
     * недособранной разницы (₽), surcharge_expires_at — дедлайн ожидания
     * доплаты (surcharge_wait_hours), после него заказ автоотменяется
     * командой orders:cancel-expired-surcharge.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'surcharge_amount')) {
                $table->decimal('surcharge_amount', 10, 2)->nullable()->after('refunded_amount');
            }
            if (! Schema::hasColumn('orders', 'surcharge_expires_at')) {
                $table->dateTime('surcharge_expires_at')->nullable()->after('surcharge_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'surcharge_expires_at')) {
                $table->dropColumn('surcharge_expires_at');
            }
            if (Schema::hasColumn('orders', 'surcharge_amount')) {
                $table->dropColumn('surcharge_amount');
            }
        });
    }
};
