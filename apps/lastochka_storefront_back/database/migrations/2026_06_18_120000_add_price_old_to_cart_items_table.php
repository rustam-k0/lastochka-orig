<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Оригинальная цена ДО скидки (для зачёркнутой цены в корзине и заказе).
     * Снапшотится на момент добавления в корзину, как и price.
     * null — если у товара нет скидки.
     */
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->integer('price_old')->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropColumn('price_old');
        });
    }
};
