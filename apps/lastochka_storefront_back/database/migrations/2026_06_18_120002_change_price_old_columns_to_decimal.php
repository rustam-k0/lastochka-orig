<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * price_old создавались как int (миграции 120000/120001), но вся денежная
 * цепочка переведена в decimal(12,2) ещё в 2026_06_12_100000 — включая
 * products.price_old, cart_items.price, order_items.price. Базовая цена до
 * скидки берётся из products.price_old (decimal) и может содержать копейки,
 * поэтому int обрезал бы их и рассинхронил с price. Приводим к decimal(12,2)
 * nullable, как остальные денежные поля.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->decimal('price_old', 12, 2)->nullable()->change();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('price_old', 12, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->integer('price_old')->nullable()->change();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->integer('price_old')->nullable()->change();
        });
    }
};
