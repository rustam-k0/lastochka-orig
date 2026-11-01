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
        Schema::create('order_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->tinyInteger('rating')->comment('Рейтинг от 1 до 5');
            $table->text('description')->nullable()->comment('Описание отзыва');
            $table->string('author_name', 100)->nullable()->comment('Имя автора');
            $table->string('author_phone', 20)->nullable()->comment('Телефон автора');
            $table->string('source')->default('storefront')->comment('Источник отзыва');
            $table->timestamps();
            $table->softDeletes();

            // Один отзыв на заказ от одного пользователя
            $table->unique(['order_id', 'user_id']);

            // Индексы для поиска отзывов пользователя и по рейтингу
            $table->index('user_id');
            $table->index(['order_id', 'rating']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_reviews');
    }
};
