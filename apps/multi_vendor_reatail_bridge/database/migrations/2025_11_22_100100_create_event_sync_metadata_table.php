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
        Schema::create('event_sync_metadata', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('entity_type', 50);
            $table->string('entity_id')->nullable();
            $table->string('source', 50); // retailcrm, bagisto
            $table->string('external_id', 191);
            $table->string('shop_code', 50)->nullable();
            $table->uuid('last_event_id')->nullable();
            $table->timestamp('last_event_timestamp')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->unique(['entity_type', 'source', 'external_id', 'shop_code'], 'event_sync_meta_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_sync_metadata');
    }
};
