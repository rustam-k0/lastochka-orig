<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Сетевой каталог (network_catalog): цены/остатки товара пер-магазин.
 * «Товар есть в магазине» = существует строка store_products (появляется
 * с первой ценой/остатком магазина). Шаблон: category_store.
 * Аддитивно, форк-безопасно (в форках таблица останется пустой).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('store_products')) {
            return;
        }

        Schema::create('store_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('price_old', 12, 2)->nullable();
            $table->integer('quantity')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'product_id']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_products');
    }
};
