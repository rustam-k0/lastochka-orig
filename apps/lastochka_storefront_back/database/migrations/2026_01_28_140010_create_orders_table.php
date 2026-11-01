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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('source', 50)->default('storefront');
            $table->string('status', 30);
            $table->string('payment_type', 20);
            $table->boolean('is_paid')->default(false);
            $table->integer('subtotal');
            $table->integer('discount')->default(0);
            $table->integer('delivery_cost')->default(0);
            $table->integer('total');
            $table->integer('items_count');
            $table->dateTime('delivery_slot_start')->nullable();
            $table->dateTime('delivery_slot_end')->nullable();
            $table->string('delivery_slot_display', 50)->nullable();
            $table->string('shipping_method', 20)->default('courier');
            $table->string('tracking_number', 100)->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index('store_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
