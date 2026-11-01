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
        Schema::create('merchant_sale_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_sale_id');
            $table->unsignedBigInteger('product_id');
            $table->timestamp('created_at')->nullable();

            $table->foreign('merchant_sale_id')
                ->references('id')
                ->on('merchant_sales')
                ->onDelete('cascade');

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->onDelete('cascade');

            $table->unique(['merchant_sale_id', 'product_id'], 'merchant_sale_products_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_sale_products');
    }
};
