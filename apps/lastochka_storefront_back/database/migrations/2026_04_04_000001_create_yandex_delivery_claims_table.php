<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('yandex_delivery_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->string('claim_id', 64)->unique()->nullable();
            $table->string('status', 40)->default('new');
            $table->unsignedInteger('price')->default(0);
            $table->string('currency', 3)->default('RUB');
            $table->unsignedInteger('eta_minutes')->nullable();
            $table->dateTime('due_at');
            $table->string('courier_name', 255)->nullable();
            $table->string('courier_phone', 20)->nullable();
            $table->string('tracking_url', 500)->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('yandex_delivery_claims');
    }
};
