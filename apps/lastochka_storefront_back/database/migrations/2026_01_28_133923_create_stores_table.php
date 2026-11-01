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
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('source', 50);
            $table->string('name', 200);
            $table->string('image_path', 500)->nullable();
            $table->integer('min_order_amount')->nullable();
            $table->integer('delivery_price')->nullable();
            $table->integer('free_delivery_from')->nullable();
            $table->decimal('rating', 2, 1)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->integer('slot_duration')->nullable();
            $table->boolean('is_open_24_7')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // Индексы
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
