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
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedTinyInteger('rating');
            $table->text('description')->nullable();
            $table->boolean('is_published')->default(false);
            $table->string('author_name', 255)->nullable();
            $table->string('author_phone', 20)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('shop_id')->references('id')->on('shops')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');

            $table->index('shop_id');
            $table->index('product_id');
            $table->index('is_published');
            $table->index('rating');
            $table->index(['shop_id', 'product_id', 'is_published', 'created_at'], 'product_reviews_composite_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
    }
};
