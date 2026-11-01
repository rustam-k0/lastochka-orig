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
        Schema::create('product_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->string('image_path', 500)->nullable();
            $table->json('meta')->nullable();
            $table->boolean('show_on_store_page')->default(false);
            $table->boolean('show_in_catalog')->default(false);
            $table->boolean('show_on_home')->default(false);
            $table->integer('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // Индексы
            $table->index(['store_id', 'is_active', 'show_on_store_page']);
            $table->index(['store_id', 'is_active', 'show_in_catalog']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_groups');
    }
};
