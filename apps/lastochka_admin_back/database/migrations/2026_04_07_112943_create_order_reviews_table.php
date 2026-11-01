<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('order_reviews')) {
            Schema::create('order_reviews', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id')->comment('ID заказа');
                $table->unsignedBigInteger('user_id')->nullable()->comment('ID клиента (резолвится через event_sync_metadata)');
                $table->tinyInteger('rating')->comment('Рейтинг от 1 до 5');
                $table->text('description')->nullable()->comment('Описание отзыва');
                $table->string('author_name', 100)->nullable()->comment('Имя автора');
                $table->string('author_phone', 20)->nullable()->comment('Телефон автора');
                $table->string('source', 32)->nullable()->comment('Источник отзыва (storefront, admin и т.д.)');
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');

                $table->index('order_id');
                $table->index('user_id');
                $table->index(['order_id', 'rating']);

                $table->unique(['order_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_reviews');
    }
};
