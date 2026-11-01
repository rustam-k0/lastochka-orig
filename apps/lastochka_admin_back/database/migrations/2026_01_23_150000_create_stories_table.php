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
        Schema::create('stories', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->dateTime('expires_at');
            $table->enum('link_type', ['none', 'product', 'shop', 'external'])->default('none');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->string('external_url', 500)->nullable();
            $table->integer('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('product_id')->references('id')->on('products')->onDelete('set null');
            $table->foreign('shop_id')->references('id')->on('shops')->onDelete('set null');

            $table->index('expires_at');
            $table->index('is_active');
            $table->index('position');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stories');
    }
};
