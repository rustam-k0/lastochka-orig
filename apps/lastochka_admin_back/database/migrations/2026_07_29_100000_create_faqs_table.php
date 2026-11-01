<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Справочник FAQ («Вопросы и ответы») для клиентского приложения.
     * Ведётся в админке, синхронизируется в storefront_back через RabbitMQ.
     */
    public function up(): void
    {
        if (!Schema::hasTable('faqs')) {
            Schema::create('faqs', function (Blueprint $table) {
                $table->id();
                $table->string('question');
                $table->text('answer');
                $table->boolean('is_active')->default(true);
                $table->integer('position')->nullable();
                $table->softDeletes();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('faqs');
    }
};
