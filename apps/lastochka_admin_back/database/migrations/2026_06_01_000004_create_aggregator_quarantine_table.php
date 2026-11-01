<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Карантин товаров, не попавших на витрину (aggregator-режим, FOODLINK-10874).
 *
 * Персистентный список со счётчиком/причиной/датой — лог-канал этого не даёт.
 * Причины: no_ean (нет штрихкода), double_match (штрихкоды совпали с разными
 * платформенными карточками). См. docs/aggregator-mode.md §5.1, §6.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('aggregator_quarantine')) {
            return;
        }

        Schema::create('aggregator_quarantine', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('local_product_id')->nullable();
            $table->string('incoming_title', 500)->nullable();
            $table->enum('reason', ['no_ean', 'double_match']);
            $table->json('matched_platform_product_ids')->nullable();
            $table->json('payload_snapshot')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamps();

            $table->index('reason', 'idx_reason');
            $table->index('resolved_at', 'idx_unresolved');
            $table->index('shop_id', 'idx_shop');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aggregator_quarantine');
    }
};
