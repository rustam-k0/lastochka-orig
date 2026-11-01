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
        Schema::create('stories', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->string('thumbnail_path', 500);
            $table->string('link_type', 20)->default('none');
            $table->foreignId('link_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('link_store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('link_external_url', 500)->nullable();
            $table->dateTime('expires_at');
            $table->boolean('is_active')->default(true);
            $table->integer('position')->default(0);
            $table->timestamps();
            $table->softDeletes();

            // Индексы
            $table->index(['is_active', 'expires_at']);
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
