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
        Schema::create('order_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('original_id')->nullable();
            $table->string('country', 100);
            $table->string('city', 100);
            $table->string('street', 200);
            $table->string('house_number', 20);
            $table->string('entrance', 10)->nullable();
            $table->string('floor', 10)->nullable();
            $table->string('apartment', 20)->nullable();
            $table->string('intercom', 20)->nullable();
            $table->string('comment', 500)->nullable();
            $table->string('full_address', 500)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_addresses');
    }
};
