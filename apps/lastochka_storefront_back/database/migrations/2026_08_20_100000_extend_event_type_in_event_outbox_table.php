<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Расширение event_outbox.event_type до 50 символов.
     *
     * Таблицу создаёт пакет comrades/laravel-rabbitmq-events, где колонка
     * рассчитана на служебные типы (created/updated/deleted) и объявлена
     * как varchar(20). Витрина публикует и доменные события: самое длинное —
     * order_surcharge_required (24 символа). В strict-режиме MySQL вставка
     * падает с «1406 Data too long for column 'event_type'», и вместе с ней
     * весь запрос: списание оплаты при завершении сборки отдавало 500 вместо
     * 402 «нужна доплата», а сборщик видел «Сервис оплаты временно недоступен».
     *
     * Дублирует одноимённую миграцию пакета намеренно: приложение чинит
     * существующие стенды сразу, не дожидаясь обновления composer-зависимости.
     * Повторное выполнение безопасно — change() на уже расширенной колонке
     * ничего не меняет.
     */
    public function up(): void
    {
        if (! Schema::hasTable('event_outbox') || ! Schema::hasColumn('event_outbox', 'event_type')) {
            return;
        }

        Schema::table('event_outbox', function (Blueprint $table) {
            $table->string('event_type', 50)->change();
        });
    }

    /**
     * Откат не сужает колонку обратно: строки с длинными доменными типами
     * были бы обрезаны или уронили бы сам откат.
     */
    public function down(): void
    {
    }
};
