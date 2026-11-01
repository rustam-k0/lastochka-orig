<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * История изменений заказа (Platrum MEN-IN-DEV-11270). Пишется явными
     * вызовами OrderHistory::log() из admin_back/purchase_back/delivery_back —
     * это одна физическая таблица в общей БД (mendev_djami), не дублируется.
     */
    public function up(): void
    {
        if (Schema::hasTable('order_history')) {
            return;
        }

        Schema::create('order_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('action', 50);
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('order_id');
            $table->index('action');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_history');
    }
};
