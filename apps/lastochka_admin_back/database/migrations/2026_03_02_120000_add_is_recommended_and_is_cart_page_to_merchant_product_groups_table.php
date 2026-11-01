<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('merchant_product_groups', 'is_recommended')) {
            return;
        }

        Schema::table('merchant_product_groups', function (Blueprint $table) {
            $table->boolean('is_recommended')->default(false)->comment('Рекомендуемая группа товаров');
            $table->boolean('is_cart_page')->default(false)->comment('Показывать в корзине');

            // Добавляем индексы для быстрого поиска
            $table->index('is_recommended');
            $table->index('is_cart_page');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchant_product_groups', function (Blueprint $table) {
            $table->dropIndex(['is_recommended']);
            $table->dropIndex(['is_cart_page']);
            $table->dropColumn(['is_recommended', 'is_cart_page']);
        });
    }
};