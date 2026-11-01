<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('external_id', 36)->comment('GUID товара в 1С');
            $table->string('name', 255)->comment('Наименование на момент заказа');
            $table->decimal('quantity', 15, 3)->default(1);
            $table->decimal('price', 15, 2)->default(0)->comment('Цена за единицу');
            $table->decimal('total', 15, 2)->default(0)->comment('Сумма по позиции');
            $table->timestamps();

            $table->index('order_id');
            $table->index('external_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
