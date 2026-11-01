<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Статичные страницы витрины (политика конфиденциальности, оферта и прочие).
     * Ведутся в админке, синхронизируются в storefront_back через RabbitMQ.
     */
    public function up(): void
    {
        if (!Schema::hasTable('pages')) {
            Schema::create('pages', function (Blueprint $table) {
                $table->id();
                $table->string('slug', 100)->unique();
                $table->string('title');
                $table->longText('content');
                $table->string('meta_title')->nullable();
                $table->text('meta_description')->nullable();
                $table->integer('position')->nullable();
                $table->boolean('is_published')->default(false);
                $table->dateTime('published_at')->nullable();
                $table->boolean('is_system')->default(false);
                $table->softDeletes();
                $table->timestamps();

                $table->index('is_published');
                $table->index('position');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
