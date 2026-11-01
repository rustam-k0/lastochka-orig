<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Вакансии раздела «Работа в компании».
     * Наполняются из админки через RabbitMQ (события vacancy.*),
     * витрина только читает.
     */
    public function up(): void
    {
        Schema::create('vacancies', function (Blueprint $table) {
            $table->id();
            $table->string('source')->nullable();
            $table->string('external_id')->nullable();
            $table->string('title');
            $table->string('city')->nullable();
            $table->string('work_format', 20)->nullable();
            $table->string('employment_type', 20)->nullable();
            $table->string('work_schedule')->nullable();
            $table->string('salary')->nullable();
            $table->text('short_description')->nullable();
            $table->text('description')->nullable();
            $table->text('responsibilities')->nullable();
            $table->text('requirements')->nullable();
            $table->text('conditions')->nullable();
            $table->integer('position')->nullable();
            $table->boolean('is_published')->default(false);
            $table->dateTime('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['source', 'external_id']);
            $table->index('is_published');
            $table->index('position');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacancies');
    }
};
