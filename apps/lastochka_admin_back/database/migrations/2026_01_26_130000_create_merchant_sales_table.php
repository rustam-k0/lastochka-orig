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
        Schema::create('merchant_sales', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedTinyInteger('discount_percent');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('shop_id')->references('id')->on('shops')->onDelete('cascade');

            $table->index('shop_id');
            $table->index('starts_at');
            $table->index('ends_at');
            $table->index('is_active');
            $table->index(['shop_id', 'starts_at', 'ends_at', 'is_active'], 'merchant_sales_active_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_sales');
    }
};
