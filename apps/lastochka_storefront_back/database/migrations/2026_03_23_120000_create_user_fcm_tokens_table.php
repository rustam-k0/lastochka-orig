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
        if (Schema::hasTable('user_fcm_tokens')) {
            return;
        }

        Schema::create('user_fcm_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('token')->unique();
            $table->string('device_type')->nullable(); // 'ios' or 'android'
            $table->string('device_name')->nullable();
            $table->integer('period')->nullable(); // Период уведомлений (в часах)
            $table->string('sound')->nullable(); // Настройка звука
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_fcm_tokens');
    }
};