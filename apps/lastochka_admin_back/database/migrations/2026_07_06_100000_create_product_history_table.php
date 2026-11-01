<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * История изменений статуса активности товара (Platrum LASTOCHKI-JAMI-11983).
     * Нужна чтобы разбирать инциденты «товар сам включился» — кто/что изменило
     * status_id и когда. Пишется явно из ProductHide/ProductPublish (staff) и
     * ProductEventTransformer::afterCreate (system, начальный статус из 1С).
     */
    public function up(): void
    {
        if (Schema::hasTable('product_history')) {
            return;
        }

        Schema::create('product_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('action', 50);
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('product_id');
            $table->index('action');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_history');
    }
};
