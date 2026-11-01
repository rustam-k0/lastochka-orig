<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Оригинальная цена ДО скидки на момент оформления заказа.
     * Копируется из cart_items.price_old, чтобы в заказе можно было показать
     * зачёркнутую старую цену рядом с фактической (даже если позже цена/акция
     * товара изменятся). null — если у товара не было скидки.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->integer('price_old')->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('price_old');
        });
    }
};
