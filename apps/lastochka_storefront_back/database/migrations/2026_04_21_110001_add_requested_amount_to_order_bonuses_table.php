<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_bonuses', function (Blueprint $table) {
            $table->integer('requested_amount')->nullable()->after('amount')
                ->comment('Запрошенная клиентом сумма при создании заказа (снапшот). Для type=spend — сколько клиент запросил к списанию; для type=earn — игнорируется.');
        });

        // Backfill: для существующих snapshot'ов принимаем amount как «запрошенное»
        // (на момент создания оно равно фактическому — пересчёта ещё не было).
        DB::table('order_bonuses')
            ->whereNull('requested_amount')
            ->update(['requested_amount' => DB::raw('amount')]);
    }

    public function down(): void
    {
        Schema::table('order_bonuses', function (Blueprint $table) {
            $table->dropColumn('requested_amount');
        });
    }
};
