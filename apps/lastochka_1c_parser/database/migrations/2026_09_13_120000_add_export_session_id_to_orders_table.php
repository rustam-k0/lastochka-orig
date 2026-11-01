<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Сессия обмена 1С, которой заказ отдан на mode=query и ещё не подтверждён mode=success.
 *
 * exported_at теперь ставится только по подтверждению 1С: если 1С не загрузила
 * выгрузку (ошибка на её стороне, success не пришёл), заказ уйдёт в следующей сессии.
 * Колонка нужна, чтобы повторный query той же сессии (1С опрашивает, пока не получит
 * пустой ответ) не отдавал уже выданные заказы, а success подтверждал только их.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'export_session_id')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->string('export_session_id', 100)->nullable()->after('exported_at');
            $table->index('export_session_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('orders', 'export_session_id')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['export_session_id']);
            $table->dropColumn('export_session_id');
        });
    }
};
