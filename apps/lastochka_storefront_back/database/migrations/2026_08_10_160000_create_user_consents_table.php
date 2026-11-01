<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_consents')) {
            return;
        }

        Schema::create('user_consents', function (Blueprint $table) {
            $table->id();
            // nullable: вакансия и обратная связь доступны гостям
            $table->foreignId('user_id')->nullable()->index()
                ->constrained()->nullOnDelete();
            $table->string('document_type', 32)->comment('privacy_policy | personal_data | public_offer | adult');
            $table->string('context', 32)->comment('Источник: auth | adult_confirm | order | vacancy | feedback');
            $table->boolean('accepted')->default(true)->comment('Выбор пользователя (для 18+ фиксируется и отказ)');
            $table->string('ip', 45)->nullable();
            $table->json('meta')->nullable()->comment('Контекстные данные: order_id, контакты гостя и т.п.');
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_consents');
    }
};
