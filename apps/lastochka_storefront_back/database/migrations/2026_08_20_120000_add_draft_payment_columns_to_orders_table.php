<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Поля черновика заказа (модуль draft_orders_until_payment).
     *
     * awaiting_payment_until — дедлайн ожидания подтверждения холда. По его
     * истечении orders:cancel-unpaid отменяет черновик и возвращает остаток.
     *
     * draft_cart_item_ids — id позиций корзины, вошедших в черновик. Корзина
     * при создании черновика НЕ очищается (клиент может уйти со страницы
     * оплаты и должен вернуться к своим товарам), поэтому при финализации
     * удаляем именно эти позиции, а не всё содержимое магазина: товар,
     * добавленный в корзину во время оплаты, обязан остаться.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'awaiting_payment_until')) {
                $table->timestamp('awaiting_payment_until')->nullable()->after('surcharge_expires_at');
            }

            if (! Schema::hasColumn('orders', 'draft_cart_item_ids')) {
                $table->json('draft_cart_item_ids')->nullable()->after('awaiting_payment_until');
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            // Крон выбирает протухшие черновики: status + дедлайн.
            $table->index(['status', 'awaiting_payment_until'], 'orders_status_awaiting_payment_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_status_awaiting_payment_idx');
            $table->dropColumn(['awaiting_payment_until', 'draft_cart_item_ids']);
        });
    }
};
