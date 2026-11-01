<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Справочник сетевых принтеров печати. Ведётся в админке курьерки,
     * delivery_back читает эту же таблицу (общая БД) и отдаёт список
     * курьерскому приложению запросом `printers`.
     *
     * UNIQUE на (ip_address, port) намеренно не ставим: таблица с softDeletes,
     * удалённая запись осталась бы в таблице и блокировала повторное
     * добавление того же принтера. Уникальность проверяется валидатором.
     */
    public function up(): void
    {
        if (!Schema::hasTable('printers')) {
            Schema::create('printers', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                // 45 символов — запас под IPv6 (включая zone index).
                $table->string('ip_address', 45);
                $table->unsignedSmallInteger('port');
                $table->boolean('is_active')->default(true);
                $table->softDeletes();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('printers');
    }
};
