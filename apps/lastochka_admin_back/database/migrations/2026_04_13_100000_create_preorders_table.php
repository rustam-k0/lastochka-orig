<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('preorders')) {
            return;
        }

        Schema::create('preorders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->string('full_name', 255);
            $table->string('phone', 20);
            $table->string('email', 255)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->text('comment')->nullable();
            $table->string('status', 20)->default('new');
            $table->timestamps();

            $table->index('client_id');
            $table->index('product_id');
            $table->index(['shop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preorders');
    }
};
