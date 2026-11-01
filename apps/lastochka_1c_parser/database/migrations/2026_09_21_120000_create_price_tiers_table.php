<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MID-308: ступени «цены от количества» по типу цены товара. Отдельная таблица,
 * потому что prices имеет unique(product_id, price_type) и держит одну строку
 * на тип цены. Источник: реквизит <ЦеныОтКоличества> внутри блока <Цена>.
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
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('price_type', 50)->comment('Тип цены, как в prices.price_type');
            $table->decimal('min_quantity', 10, 3)->comment('Порог количества в базовых единицах товара');
            $table->decimal('value', 15, 2)->comment('Цена за базовую единицу при количестве от порога');
            $table->timestamps();

            $table->unique(['product_id', 'price_type', 'min_quantity'], 'price_tiers_product_type_min_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_tiers');
    }
};
