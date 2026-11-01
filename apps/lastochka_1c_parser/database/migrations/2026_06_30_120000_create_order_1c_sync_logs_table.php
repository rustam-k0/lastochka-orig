<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_1c_sync_logs')) {
            return;
        }

        Schema::create('order_1c_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->comment('orders.id в 1c_parser');
            $table->unsignedBigInteger('shop_order_id')->nullable()
                ->comment('id заказа в сторфронте (денормализация для чтения из admin_back)');
            $table->string('status', 20)->comment('sent, error, pending_retry, success');
            $table->unsignedInteger('attempt_number')->default(1);
            $table->text('message')->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->index(['order_id', 'created_at']);
            $table->index(['shop_order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_1c_sync_logs');
    }
};
