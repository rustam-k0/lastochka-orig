<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Справочник вакансий раздела «Работа в компании».
     * Ведётся в админке, синхронизируется в storefront_back через RabbitMQ.
     */
    public function up(): void
    {
        if (!Schema::hasTable('vacancies')) {
            Schema::create('vacancies', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('city')->nullable();
                $table->string('work_format', 20)->nullable();
                $table->string('employment_type', 20)->nullable();
                $table->string('work_schedule')->nullable();
                $table->string('salary')->nullable();
                $table->text('short_description');
                $table->text('description');
                $table->text('responsibilities')->nullable();
                $table->text('requirements')->nullable();
                $table->text('conditions')->nullable();
                $table->integer('position')->nullable();
                $table->boolean('is_published')->default(false);
                $table->dateTime('published_at')->nullable();
                $table->softDeletes();
                $table->timestamps();

                $table->index('is_published');
                $table->index('position');
                $table->index('city');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vacancies');
    }
};
