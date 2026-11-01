<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('merchant_promocodes', function (Blueprint $table) {
            $table->enum('discount_type', ['percent', 'fixed'])->default('percent')->after('discount_percent');
            $table->unsignedInteger('discount_value')->nullable()->after('discount_type');
            $table->enum('binding_type', ['all', 'products', 'categories'])->default('all')->after('discount_value');
        });

        // Data migration: copy discount_percent → discount_value for existing rows
        DB::table('merchant_promocodes')
            ->whereNotNull('discount_percent')
            ->update(['discount_value' => DB::raw('discount_percent')]);

        if (!Schema::hasTable('merchant_promocode_products')) {
            Schema::create('merchant_promocode_products', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('merchant_promocode_id');
                $table->unsignedBigInteger('product_id');
                $table->timestamps();

                $table->foreign('merchant_promocode_id')->references('id')->on('merchant_promocodes')->onDelete('cascade');
                $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');

                $table->unique(['merchant_promocode_id', 'product_id'], 'promo_product_unique');
            });
        }

        if (!Schema::hasTable('merchant_promocode_categories')) {
            Schema::create('merchant_promocode_categories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('merchant_promocode_id');
                $table->unsignedBigInteger('category_id');
                $table->timestamps();

                $table->foreign('merchant_promocode_id')->references('id')->on('merchant_promocodes')->onDelete('cascade');
                $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');

                $table->unique(['merchant_promocode_id', 'category_id'], 'promo_category_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_promocode_categories');
        Schema::dropIfExists('merchant_promocode_products');

        Schema::table('merchant_promocodes', function (Blueprint $table) {
            $table->dropColumn(['binding_type', 'discount_value', 'discount_type']);
        });
    }
};
