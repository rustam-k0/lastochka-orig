<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Лесенка «цены от количества» товара в магазине (MID-308): одна строка на
 * ступень. Порог и цена хранятся в базовых единицах товара (шт, кг, л), как
 * в событиях цен 1С и как вводит оператор, поэтому смена кванта таблицу не
 * затрагивает.
 *
 * Общая БД курьерских модулей: пишет admin_back (консьюмер цен и мутации
 * карточки товара), читают delivery_back и purchase_back.
 *
 * shop_id NOT NULL: в сетевом каталоге это магазин цены, в обычном режиме
 * магазин товара. У легаси-строк prices shop_id бывает NULL, здесь так нельзя:
 * UNIQUE по (product_id, shop_id, min_quantity) с NULL не работает.
 *
 * Guard: таблицу мог создать другой форк/ветка (общая БД docker-модулей).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('price_tiers')) {
            return;
        }

        Schema::create('price_tiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('shop_id');
            $table->decimal('min_quantity', 10, 3)->comment('Порог: от какого количества действует цена, в базовых единицах товара');
            $table->decimal('value', 12, 2)->comment('Цена за базовую единицу товара на этой ступени');
            $table->timestamps();

            $table->unique(['product_id', 'shop_id', 'min_quantity'], 'price_tiers_product_shop_min_unique');
            $table->index(['shop_id', 'product_id'], 'price_tiers_shop_product_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_tiers');
    }
};
