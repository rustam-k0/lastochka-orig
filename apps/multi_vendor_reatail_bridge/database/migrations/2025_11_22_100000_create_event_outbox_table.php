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
        Schema::create('event_outbox', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('event_id')->index();
            $table->string('entity_type'); // e.g. orders
            $table->string('entity_id')->nullable();
            $table->string('event_type'); // e.g. order.created
            $table->string('routing_key', 100)->nullable();
            $table->json('payload');
            $table->string('status')->default('pending'); // pending, processing, published, failed
            $table->unsignedInteger('retries')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'event_type']);
            $table->index(['entity_type', 'published_at'], 'idx_entity_published');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_outbox');
    }
};
