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
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title')->comment('Заголовок уведомления об акции');
            $table->text('short_description')->comment('Краткое описание акции');
            $table->text('description')->comment('Полное описание акции');
            $table->unsignedBigInteger('promocode_id')->nullable()->comment('Связанный промокод');
            $table->boolean('is_active')->default(true)->comment('Активно ли уведомление');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('promocode_id')->references('id')->on('merchant_promocodes')->onDelete('set null');
            $table->index(['is_active', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};