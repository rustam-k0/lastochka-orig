<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Переименование scope 'electrohub' → 'platform' в promocodes.
 *
 * Значение было привязано к конкретному проекту, но код
 * синхронизируется между форками — переходим на нейтральное 'platform'.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('promocodes') && Schema::hasColumn('promocodes', 'scope')) {
            DB::table('promocodes')
                ->where('scope', 'electrohub')
                ->update(['scope' => 'platform']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('promocodes') && Schema::hasColumn('promocodes', 'scope')) {
            DB::table('promocodes')
                ->where('scope', 'platform')
                ->update(['scope' => 'electrohub']);
        }
    }
};
