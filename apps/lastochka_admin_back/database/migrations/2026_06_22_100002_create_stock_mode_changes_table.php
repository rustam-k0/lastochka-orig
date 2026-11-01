<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_mode_changes', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 16);   // category|product
            $table->unsignedBigInteger('entity_id');
            $table->string('old_mode', 16)->nullable();
            $table->string('new_mode', 16)->nullable();
            $table->integer('old_fixed')->nullable();
            $table->integer('new_fixed')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_mode_changes');
    }
};
