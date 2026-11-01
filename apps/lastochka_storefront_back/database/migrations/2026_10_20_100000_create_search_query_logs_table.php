<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Серверный лог поиска в магазине — источник отчёта «искали, но не нашли»
 * (Superset, «Операционные отчёты»). Пишет {@see \App\Services\Search\SearchQueryLogger}
 * после отправки ответа на GET /api/v1/search/stores/{store}/products.
 *
 * Поиск в мобилке срабатывает на каждую букву: запросы одного клиента,
 * продолжающие друг друга, склеиваются в серию (series_id), итоговым
 * считается самый длинный запрос серии (is_final = 1).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('search_query_logs')) {
            return;
        }

        Schema::create('search_query_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('user_id')->nullable()->comment('Авторизованный клиент (Bearer), иначе NULL');
            $table->string('actor_key', 64)->comment('u:<user_id> или d:<sha1(ip|user-agent)> — ключ склейки серии');
            $table->string('query', 255);
            $table->string('query_normalized', 255)->comment('нижний регистр, схлопнутые пробелы');
            $table->unsignedInteger('results_count')->default(0)->comment('total выдачи');
            $table->json('unavailable_product_ids')->nullable()->comment('товары по запросу, отсечённые из-за отсутствия остатка');
            $table->unsignedInteger('unavailable_count')->default(0);
            $table->unsignedBigInteger('series_id')->nullable()->comment('id первой строки серии набора');
            $table->boolean('is_final')->default(true)->comment('итоговый (самый длинный) запрос серии');
            $table->timestamp('searched_at')->useCurrent();

            $table->index(['actor_key', 'store_id', 'searched_at']);
            $table->index('series_id');
            $table->index(['is_final', 'searched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_query_logs');
    }
};
