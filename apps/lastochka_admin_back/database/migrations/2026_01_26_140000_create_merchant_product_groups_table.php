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
        Schema::create('merchant_product_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('title', 255);
            $table->json('meta')->nullable();
            $table->boolean('show_on_main')->default(false);
            $table->boolean('show_on_merchant_page')->default(true);
            $table->boolean('show_in_merchant_catalog')->default(false);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('shop_id')->references('id')->on('shops')->onDelete('cascade');

            $table->index('shop_id');
            $table->index('is_active');
            $table->index('show_on_main');
            $table->index('show_on_merchant_page');
            $table->index('show_in_merchant_catalog');
            $table->index(['shop_id', 'is_active', 'sort_order'], 'merchant_product_groups_composite_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_product_groups');
    }
};
