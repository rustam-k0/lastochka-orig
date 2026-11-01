<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Статус оплаты для фильтра в карточке/списке заказов (Platrum MEN-IN-DEV-11274).
     * Значения (полная детализация из storefront_back payment_transactions,
     * см. ClientOrderResource::derivePaymentStatus): pending, hold, paid,
     * failed, refunded. UI-фильтр бакетит pending+hold в «Ожидает оплаты»
     * на уровне OrderFilter, здесь хранится полная фиделити без потерь.
     */
    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'payment_status')) {
                $table->string('payment_status', 20)->nullable()->after('paid_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'payment_status')) {
                $table->dropColumn('payment_status');
            }
        });
    }
};
