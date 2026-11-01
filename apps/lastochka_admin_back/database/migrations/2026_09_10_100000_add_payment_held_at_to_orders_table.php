<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MID-285: момент холдирования средств клиента, присланный витриной.
     *
     * Модуль «Оформление заказа без слотов» отменяет выбор времени доставки, и
     * сборщику с курьером вместо часового слота показывается окно «холд → +30
     * минут». Точку отсчёта знает только витрина (холд живёт в её payment_
     * transactions), поэтому она приезжает событием и хранится здесь.
     *
     * paid_at рядом означает другое — момент списания (capture), который при
     * двухстадийной оплате наступает уже после сборки.
     */
    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'payment_held_at')) {
                $table->timestamp('payment_held_at')->nullable()->after('paid_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'payment_held_at')) {
                $table->dropColumn('payment_held_at');
            }
        });
    }
};
