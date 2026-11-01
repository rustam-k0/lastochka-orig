<?php

declare(strict_types=1);

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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku', 100);
            $table->string('title', 300)->nullable();
            $table->text('description')->nullable();
            $table->integer('price')->nullable();
            $table->integer('price_old')->nullable();
            $table->string('currency', 3)->default('RUB');
            $table->integer('quantity')->default(0);
            $table->enum('unit_type', ['piece', 'kg'])->default('piece');
            $table->text('composition')->nullable();
            $table->decimal('proteins', 6, 2)->nullable();
            $table->decimal('fats', 6, 2)->nullable();
            $table->decimal('carbohydrates', 6, 2)->nullable();
            $table->decimal('calories', 8, 2)->nullable();
            $table->string('manufacturer', 200)->nullable();
            $table->string('brand', 200)->nullable();
            $table->string('country', 100)->nullable();
            $table->integer('weight')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['store_id', 'sku']);
            $table->index(['store_id', 'category_id', 'is_active']);
            $table->index(['store_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
