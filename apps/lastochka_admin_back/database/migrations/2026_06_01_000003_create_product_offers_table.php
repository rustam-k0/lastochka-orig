<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Оффер магазина-поставщика на платформенный товар (aggregator-режим, FOODLINK-10874).
 *
 * Один оффер = одно сочетание (platform_product, shop). Денормализованные
 * price/qty для витрины (источник истории — prices/remains). Вторичный ключ
 * (local_product_id, shop_id) = стабильный id товара поставщика по GUID 1С
 * (см. docs/aggregator-mode.md §5.1, §6.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_offers')) {
            return;
        }

        Schema::create('product_offers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('platform_product_id');
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('local_product_id');
            $table->integer('price')->default(0);
            $table->decimal('qty', 10, 3)->default(0);
            $table->decimal('min_qty', 10, 3)->default(1);
            $table->boolean('is_active')->default(true);
            $table->integer('sort')->default(0);
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();

            $table->unique(['platform_product_id', 'shop_id'], 'uniq_offer');
            $table->index('shop_id', 'idx_shop');
            // Вторичный ключ: поиск оффера того же поставщика (GUID-стабильный) ДО EAN-матчинга.
            // UNIQUE — инвариант «один оффер на (товар поставщика, магазин)»; импортёр
            // полагается на ->first() по этому ключу (см. AggregatorOfferImporter::handle).
            $table->unique(['local_product_id', 'shop_id'], 'uniq_offer_supplier');
            $table->index(['is_active', 'sort'], 'idx_active_sort');

            $table->foreign('platform_product_id', 'fk_offer_platform')
                ->references('id')->on('platform_products')
                ->onDelete('cascade');
            $table->foreign('shop_id', 'fk_offer_shop')
                ->references('id')->on('shops');
            $table->foreign('local_product_id', 'fk_offer_local')
                ->references('id')->on('products');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_offers');
    }
};
