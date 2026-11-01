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
        Schema::create('merchant_banners', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->enum('link_type', ['none', 'product', 'category'])->default('none');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('shop_id');
            $table->boolean('is_active')->default(true);
            $table->integer('position')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('product_id')->references('id')->on('products')->onDelete('set null');
            $table->foreign('category_id')->references('id')->on('categories')->onDelete('set null');
            $table->foreign('shop_id')->references('id')->on('shops')->onDelete('cascade');

            $table->index('shop_id');
            $table->index('link_type');
            $table->index(['is_active', 'shop_id'], 'merchant_banners_active_shop_idx');
            $table->index(['shop_id', 'position'], 'merchant_banners_shop_position_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_banners');
    }
};
