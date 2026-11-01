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
        Schema::create('merchant_product_group_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_product_group_id');
            $table->unsignedBigInteger('product_id');
            $table->integer('sort_order')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->foreign('merchant_product_group_id')
                ->references('id')
                ->on('merchant_product_groups')
                ->onDelete('cascade');

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->onDelete('cascade');

            $table->unique(['merchant_product_group_id', 'product_id'], 'merchant_product_group_items_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_product_group_items');
    }
};
