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
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('source')->default('admin')->comment('Источник данных');
            $table->string('external_id')->comment('ID в источнике');
            $table->string('title')->comment('Заголовок уведомления об акции');
            $table->text('short_description')->comment('Краткое описание акции');
            $table->text('description')->comment('Полное описание акции');
            $table->string('image_path')->nullable()->comment('Путь к изображению');
            $table->foreignId('promocode_id')->nullable()->constrained('promocodes')->nullOnDelete()->comment('Связанный промокод');
            $table->boolean('is_active')->default(true)->comment('Активно ли уведомление');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'created_at']);
            $table->unique(['source', 'external_id']);
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