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
        Schema::create('entity_hashes', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type');
            $table->string('entity_id');
            $table->string('hash');
            $table->timestamps();

            $table->unique(['entity_type', 'entity_id']);
            $table->index(['entity_type', 'entity_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entity_hashes');
    }
};
