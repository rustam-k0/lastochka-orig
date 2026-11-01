<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2.6 — Consolidated refund flow.
 *
 * Добавляет timestamp для идемпотентной отметки финализации marketplace client-order'а.
 *
 * Значение != null означает что `FinalizeClientOrderAction` уже отработал для этого sub-order'а
 * (и для всех остальных sub-orders его client_order_id) — повторный вызов должен быть no-op.
 *
 * См. docs/design-analysis/marketplace-model.md §5.1 (M.10=b).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'client_order_finalized_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->timestamp('client_order_finalized_at')->nullable()->after('refunded_amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'client_order_finalized_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('client_order_finalized_at');
            });
        }
    }
};
