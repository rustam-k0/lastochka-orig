<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Статичные страницы витрины (политика конфиденциальности, оферта и прочие).
     * Наполняются из админки через RabbitMQ (события page.*), витрина только читает.
     */
    public function up(): void
    {
        if (Schema::hasTable('pages')) {
            return;
        }

        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('source')->nullable();
            $table->string('external_id')->nullable();
            $table->string('slug', 100);
            $table->string('title');
            $table->longText('content')->nullable();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->integer('position')->nullable();
            $table->boolean('is_published')->default(false);
            $table->dateTime('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['source', 'external_id']);
            // slug намеренно не unique: от дублей защищает пара (source, external_id),
            // а unique-индекс на форке рискует дать 1062 при переливке данных.
            $table->index('slug');
            $table->index('is_published');
            $table->index('position');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
