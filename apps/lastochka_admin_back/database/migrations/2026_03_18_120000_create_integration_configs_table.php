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
        Schema::create('integration_configs', function (Blueprint $table) {
            $table->id();
            $table->string('integration_type', 50)->unique();
            $table->string('driver', 50);
            $table->text('credentials')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('tested_at')->nullable();
            $table->text('test_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integration_configs');
    }
};
