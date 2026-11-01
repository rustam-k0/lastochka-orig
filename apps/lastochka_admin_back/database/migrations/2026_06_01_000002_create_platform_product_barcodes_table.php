<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Штрихкоды платформенного товара (aggregator-режим, FOODLINK-10874).
 *
 * Один EAN не может принадлежать двум платформенным товарам одновременно
 * (UNIQUE по ean) — это инвариант межпоставщикового дедупа, нарушение
 * означает конфликтный мердж и блокируется на уровне БД (см. §5.1, §6.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('platform_product_barcodes')) {
            return;
        }

        Schema::create('platform_product_barcodes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('platform_product_id');
            $table->string('ean', 64);
            $table->boolean('is_primary')->default(false);
            $table->unsignedBigInteger('source_shop_id')->nullable();
            $table->timestamps();

            $table->unique('ean', 'uniq_ean');
            $table->index('platform_product_id', 'idx_platform');
            $table->index(['platform_product_id', 'is_primary'], 'idx_primary');

            $table->foreign('platform_product_id', 'fk_barcode_platform')
                ->references('id')->on('platform_products')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_product_barcodes');
    }
};
