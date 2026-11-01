<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Индекс на created_at для exchange_logs.
 *
 * Ночная очистка 1c:cleanup --logs удаляет записи по created_at, а индекса на
 * этом поле не было: EXPLAIN давал type=ALL с полным сканом (882 тыс. строк на
 * проде Ласточки) на каждом запуске, пока 1С параллельно писала в таблицу.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('exchange_logs')) {
            return;
        }

        // Индекс мог быть добавлен вручную на конкретном форке — не падаем.
        if (Schema::hasIndex('exchange_logs', 'exchange_logs_created_at_index')) {
            return;
        }

        Schema::table('exchange_logs', function (Blueprint $table) {
            $table->index('created_at', 'exchange_logs_created_at_index');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('exchange_logs')
            || !Schema::hasIndex('exchange_logs', 'exchange_logs_created_at_index')) {
            return;
        }

        Schema::table('exchange_logs', function (Blueprint $table) {
            $table->dropIndex('exchange_logs_created_at_index');
        });
    }
};
