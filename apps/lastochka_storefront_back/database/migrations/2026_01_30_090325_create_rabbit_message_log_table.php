<?php

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
        Schema::create('rabbit_message_log', function (Blueprint $table) {
            $table->id();
            $table->enum('direction', ['inbound', 'outbound']);
            $table->string('routing_key');
            $table->string('queue_name')->nullable();
            $table->json('payload');
            $table->string('entity_type', 50)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('external_id')->nullable();
            $table->enum('status', ['pending', 'processed', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->text('error_trace')->nullable();
            $table->json('db_snapshot')->nullable();
            $table->unsignedInteger('processing_time_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Индексы
            $table->index(['direction', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->index('routing_key');
            $table->index('external_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rabbit_message_log');
    }
};
