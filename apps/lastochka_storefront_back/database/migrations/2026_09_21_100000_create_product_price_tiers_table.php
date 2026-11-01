<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Цены от количества (MID-308): лесенка цен товара в магазине.
 * Единицы min_quantity и price: базовые единицы товара (шт, кг, л), как в
 * событии цены. store_id: в сетевом каталоге магазин оффера, в обычном
 * режиме магазин товара. Аддитивно и форк-безопасно: при выключенном модуле
 * price_tiers таблица остаётся пустой.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_price_tiers')) {
            return;
        }

        Schema::create('product_price_tiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('store_id');
            $table->decimal('min_quantity', 10, 3);
            $table->decimal('price', 12, 2);
            $table->timestamps();

            $table->unique(['product_id', 'store_id', 'min_quantity'], 'product_price_tiers_product_store_min_unique');
            $table->index(['store_id', 'product_id'], 'product_price_tiers_store_product_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_tiers');
    }
};
