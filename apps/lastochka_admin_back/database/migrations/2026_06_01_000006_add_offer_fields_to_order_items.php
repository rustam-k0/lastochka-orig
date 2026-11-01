<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Привязка позиции заказа к офферу поставщика (aggregator-режим, FOODLINK-10874).
 *
 * offer_id — ссылка на product_offers.id (nullable, set null при удалении оффера).
 * meta — снимок marketplace_product_id + external_shop_code из входящего заказа
 * Bagisto, нужен OrderAggregatorSplitter'у для группировки по магазину-поставщику
 * (см. docs/aggregator-mode.md §6.5, §7.5). Аддитивно и nullable — не ломает legacy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('order_items')) {
            return;
        }

        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'offer_id')) {
                $table->unsignedBigInteger('offer_id')->nullable()->after('product_id');
                $table->index('offer_id', 'idx_order_items_offer');
            }
            if (!Schema::hasColumn('order_items', 'meta')) {
                $table->json('meta')->nullable()->after('offer_id');
            }
        });

        // FK добавляем отдельно и только если есть таблица product_offers
        // (порядок миграций гарантирует её наличие, но guard защищает форки).
        if (Schema::hasTable('product_offers') && Schema::hasColumn('order_items', 'offer_id')) {
            try {
                Schema::table('order_items', function (Blueprint $table) {
                    $table->foreign('offer_id', 'fk_order_item_offer')
                        ->references('id')->on('product_offers')
                        ->onDelete('set null');
                });
            } catch (\Throwable $e) {
                // FK мог быть уже создан (повторный прогон) — игнорируем.
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('order_items')) {
            return;
        }

        Schema::table('order_items', function (Blueprint $table) {
            try {
                $table->dropForeign('fk_order_item_offer');
            } catch (\Throwable $e) {
            }
            if (Schema::hasColumn('order_items', 'meta')) {
                $table->dropColumn('meta');
            }
            if (Schema::hasColumn('order_items', 'offer_id')) {
                $table->dropColumn('offer_id');
            }
        });
    }
};
