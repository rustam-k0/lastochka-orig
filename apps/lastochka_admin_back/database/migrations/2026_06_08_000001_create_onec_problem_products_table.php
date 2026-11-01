<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Снимок «проблемных» товаров 1С (без обязательных реквизитов) для страницы
 * «Проблемные товары 1С» (FOODLINK-11039).
 *
 * Наполняется из RabbitMQ-диагностики (1c_parser → OneCExchangeStatusEventTransformer):
 * при каждом обмене снимок для (shop_id, кодов этого data_type) заменяется целиком.
 * Прямого доступа к БД 1c_parser у admin_back нет — это локальная проекция.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('onec_problem_products')) {
            return;
        }

        Schema::create('onec_problem_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->string('code', 50);
            $table->string('external_id', 64)->nullable();
            $table->string('sku', 191)->nullable();
            $table->string('name', 500)->nullable();
            $table->string('barcode', 191)->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'code'], 'idx_shop_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onec_problem_products');
    }
};
