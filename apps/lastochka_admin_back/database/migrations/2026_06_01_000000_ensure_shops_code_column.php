<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Гарантирует наличие колонки shops.code (aggregator-режим, FOODLINK-10874).
 *
 * shops.code исторически создаётся в foodlink_store_back; admin_back её только использует
 * (ShopEventTransformer, backfill_shops_code, URL обмена 1С, aggregator-импортёр/сплиттер),
 * но сам не создаёт. На стендах/форках без store_back (или при прогоне только admin_back
 * миграций) колонки нет. Идемпотентный guard: в проде, где store_back уже добавил code,
 * миграция ничего не делает.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('shops') || Schema::hasColumn('shops', 'code')) {
            return;
        }

        Schema::table('shops', function (Blueprint $table) {
            $table->string('code')->nullable()->unique()->after('title');
        });
    }

    public function down(): void
    {
        // Необратимо: code может уже использоваться в настройках обмена 1С и aggregator-офферах.
    }
};
