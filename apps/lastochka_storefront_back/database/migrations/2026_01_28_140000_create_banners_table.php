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
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->string('image_path', 500);
            $table->string('link_type', 20)->default('none');
            $table->foreignId('link_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('link_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->integer('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Индексы
            $table->index(['store_id', 'is_active', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};
