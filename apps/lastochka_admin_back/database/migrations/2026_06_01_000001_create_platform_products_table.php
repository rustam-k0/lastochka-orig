<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Каноническая карточка платформенного товара (aggregator-режим, FOODLINK-10874).
 *
 * Аддитивная миграция: создаёт только новую таблицу. Безопасна для форков —
 * при попадании через sync-core создаётся пустая таблица, legacy-код её игнорирует
 * (см. docs/aggregator-mode.md §3.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('platform_products')) {
            return;
        }

        Schema::create('platform_products', function (Blueprint $table) {
            $table->id();
            $table->string('canonical_title', 500);
            $table->text('canonical_description')->nullable();
            $table->string('canonical_image', 500)->nullable();
            $table->json('canonical_attributes')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('measurement_unit_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_curated')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index('category_id', 'idx_category');
            $table->index('is_active', 'idx_is_active');
        });

        // Префиксный индекс по canonical_title (как в docs/aggregator-mode.md §5.1).
        // Через raw, т.к. Blueprint не поддерживает длину префикса для VARCHAR-индекса.
        DB::statement('ALTER TABLE platform_products ADD INDEX idx_title (canonical_title(100))');
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_products');
    }
};
