<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Переименование scope 'electrohub' → 'platform' в merchant_promocodes.
 *
 * Значение было привязано к конкретному проекту (Electrohub), но код
 * синхронизируется между форками (Aladdin, Lastochka, Cron и др.),
 * поэтому переходим на нейтральное 'platform'.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('merchant_promocodes') && Schema::hasColumn('merchant_promocodes', 'scope')) {
            DB::table('merchant_promocodes')
                ->where('scope', 'electrohub')
                ->update(['scope' => 'platform']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('merchant_promocodes') && Schema::hasColumn('merchant_promocodes', 'scope')) {
            DB::table('merchant_promocodes')
                ->where('scope', 'platform')
                ->update(['scope' => 'electrohub']);
        }
    }
};
