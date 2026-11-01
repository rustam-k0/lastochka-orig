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
        Schema::create('merchant_promocodes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('title', 255);
            $table->string('code', 50);
            $table->unsignedTinyInteger('discount_percent');
            $table->date('valid_from');
            $table->date('valid_to');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('shop_id')->references('id')->on('shops')->onDelete('cascade');

            $table->index('shop_id');
            $table->index('code');
            $table->index('is_active');
            $table->unique(['shop_id', 'code'], 'merchant_promocodes_shop_code_unique');
            $table->index(['shop_id', 'is_active', 'valid_from', 'valid_to'], 'merchant_promocodes_valid_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_promocodes');
    }
};
