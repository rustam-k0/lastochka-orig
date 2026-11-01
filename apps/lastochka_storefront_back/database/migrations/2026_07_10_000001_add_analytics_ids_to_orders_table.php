<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Сквозные аналитические идентификаторы (LASTOCHKI-JAMI-11659, контракт #11642):
     * связка «поведение в приложении → заказ». Мобилка передаёт заголовки
     * X-Analytics-Session-Id / X-Analytics-Client-Id при оформлении заказа.
     * Колонки нужны, т.к. payload события order.created пересобирается
     * outbox-воркером (delay_seconds) вне HTTP-контекста.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('analytics_session_id', 64)->nullable()->after('idempotency_key');
            $table->string('analytics_device_client_id', 64)->nullable()->after('analytics_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['analytics_session_id', 'analytics_device_client_id']);
        });
    }
};
