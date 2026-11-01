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
        Schema::create('promocodes', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->string('code', 50)->unique();
            $table->string('type', 20);
            $table->integer('value');
            $table->integer('min_order_amount')->nullable();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('max_uses')->nullable();
            $table->integer('used_count')->default(0);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promocodes');
    }
};
