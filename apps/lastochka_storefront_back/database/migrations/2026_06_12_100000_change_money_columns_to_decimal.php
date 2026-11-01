<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Деньги с копейками (LASTOCHKI-JAMI-11200): цена кванта весового товара
 * рассчитывается с точностью до копейки (ЦенаКванта = ЦенаЗаКг/1000 × Квант),
 * целочисленные рубли недопустимы — суммы чеков и эквайринга должны сходиться.
 *
 * INT → DECIMAL(12,2) по всей денежной цепочке: карточка → корзина → заказ →
 * промокод. Атрибуты (nullable/default) повторяют исходные определения колонок.
 * Бонусы остаются целыми (int), eligible_amount — база начисления из дробных
 * total'ов позиций.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('price', 12, 2)->nullable()->change();
            $table->decimal('price_old', 12, 2)->nullable()->change();
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->decimal('price', 12, 2)->change();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('price', 12, 2)->change();
            $table->decimal('total', 12, 2)->change();
            $table->decimal('origin_price', 12, 2)->nullable()->change();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('subtotal', 12, 2)->change();
            $table->decimal('discount', 12, 2)->default(0)->change();
            $table->decimal('eligible_amount', 12, 2)->default(0)->change();
            $table->decimal('delivery_cost', 12, 2)->default(0)->change();
            $table->decimal('total', 12, 2)->change();
        });

        Schema::table('order_promocodes', function (Blueprint $table) {
            $table->decimal('discount_amount', 12, 2)->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->integer('price')->nullable()->change();
            $table->integer('price_old')->nullable()->change();
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->integer('price')->change();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->integer('price')->change();
            $table->integer('total')->change();
            $table->integer('origin_price')->nullable()->change();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->integer('subtotal')->change();
            $table->integer('discount')->default(0)->change();
            $table->integer('eligible_amount')->default(0)->change();
            $table->integer('delivery_cost')->default(0)->change();
            $table->integer('total')->change();
        });

        Schema::table('order_promocodes', function (Blueprint $table) {
            $table->integer('discount_amount')->change();
        });
    }
};
