<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Просмотры карточек авторизованными пользователями — источник автогруппы
 * «Смотрел, но не купил». Пишет {@see \App\Services\ProductGroup\ProductViewRecorder}
 * из события product.viewed, чистит команда product-views:prune.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_views')) {
            return;
        }

        Schema::create('product_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('views_count')->default(0);
            $table->timestamp('first_viewed_at')->nullable();
            $table->timestamp('last_viewed_at')->nullable();
            $table->timestamp('cart_removed_at')->nullable();

            $table->unique(['user_id', 'product_id']);
            $table->index(['user_id', 'last_viewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_views');
    }
};
