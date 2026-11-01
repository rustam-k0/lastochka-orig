<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Таблица атомарных блокировок Laravel (Cache::lock через database-стор).
 *
 * До неё блокировки жили в файловом кэше, который пишут два разных юзера:
 * php-fpm (www-data) и CI-раннер, прогревающий `artisan lighthouse:cache`.
 * Созданный раннером подкаталог storage/framework/cache/data/** получал его
 * первичную группу, www-data внутрь писать не мог, и Cache::lock падал с
 * «Unable to create lockable file». На проде Ласточки 11.09.2026 это уронило
 * завершение сборки 255 (заказ 223-1): сборщик не мог закрыть собранный заказ.
 *
 * Таблица общая для курьерских модулей (одна БД): пишут delivery_back
 * (завершение сборки) и admin_back (синхронизация категорий 1С).
 * Ряды короткоживущие, Laravel сам подчищает протухшие при взятии блокировки.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cache_locks')) {
            return;
        }

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary()->comment('Имя блокировки с префиксом кэша');
            $table->string('owner')->comment('Владелец: случайный идентификатор процесса, взявшего блокировку');
            $table->integer('expiration')->comment('UNIX-таймштамп, после которого блокировка считается брошенной');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cache_locks');
    }
};
