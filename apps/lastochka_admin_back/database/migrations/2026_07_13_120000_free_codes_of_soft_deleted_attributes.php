<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ALADDIN-11991: коды уже удалённых атрибутов остаются в UNIQUE-индексе
     * attributes.code и блокируют повторное создание атрибута с тем же кодом.
     * Переименовываем их в {code}__del{id} — как теперь делает хук модели
     * при удалении. Идемпотентно: уже переименованные строки не трогаем.
     */
    public function up()
    {
        if (! Schema::hasTable('attributes') || ! Schema::hasColumn('attributes', 'code')) {
            return;
        }

        DB::table('attributes')
            ->whereNotNull('deleted_at')
            ->whereRaw("code NOT REGEXP '__del[0-9]+$'")
            ->update([
                'code' => DB::raw("CONCAT(LEFT(code, 100 - CHAR_LENGTH(CONCAT('__del', id))), '__del', id)"),
            ]);
    }

    public function down()
    {
        // Исходные коды однозначно не восстановимы — откат не требуется.
    }
};
