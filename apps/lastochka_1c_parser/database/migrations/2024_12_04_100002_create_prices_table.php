<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('price_type', 50)->comment('Тип цены (Розничная, Оптовая, etc)');
            $table->decimal('value', 15, 2)->default(0);
            $table->string('currency', 3)->default('RUB');
            $table->timestamps();

            $table->unique(['product_id', 'price_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prices');
    }
};
