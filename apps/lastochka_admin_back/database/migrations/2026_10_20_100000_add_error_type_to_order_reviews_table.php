<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Тип ошибки сборки по отзыву на заказ ({@see \App\Enums\OrderReviewErrorType}):
 * недовоз, пересорт, просрочка, другое. Проставляет оператор в админке,
 * источник отчёта «Качество сборки».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('order_reviews', 'error_type')) {
            return;
        }

        Schema::table('order_reviews', function (Blueprint $table) {
            $table->string('error_type', 32)->nullable()->after('source')
                ->comment('Тип ошибки сборки: shortage, wrong_item, expired, other');
            $table->index('error_type');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('order_reviews', 'error_type')) {
            return;
        }

        Schema::table('order_reviews', function (Blueprint $table) {
            $table->dropIndex(['error_type']);
            $table->dropColumn('error_type');
        });
    }
};
