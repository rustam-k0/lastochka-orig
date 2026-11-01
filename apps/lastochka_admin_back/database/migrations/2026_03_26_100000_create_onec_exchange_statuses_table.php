<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onec_exchange_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('data_type', 50)->unique();
            $table->boolean('success')->default(true);
            $table->text('error')->nullable();
            $table->unsignedInteger('records_count')->default(0);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->json('counters')->nullable();
            $table->json('warnings')->nullable();
            $table->timestamp('last_exchange_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onec_exchange_statuses');
    }
};
