<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Информация по самовывозу в карточке заказа (Platrum MEN-IN-DEV-11279).
     * pickup_ready_at — момент готовности заказа к выдаче (проставляется
     * при переходе заказа в ORDER_ASSEMBLED, симметрично для любого типа
     * получения, используется в отображении только для самовывоза).
     * picked_up_at/picked_up_by_user_id — факт и автор подтверждения выдачи
     * (OrderPickupConfirm).
     */
    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'pickup_ready_at')) {
                $table->timestamp('pickup_ready_at')->nullable()->after('status_id');
            }

            if (!Schema::hasColumn('orders', 'picked_up_at')) {
                $table->timestamp('picked_up_at')->nullable()->after('pickup_ready_at');
            }

            if (!Schema::hasColumn('orders', 'picked_up_by_user_id')) {
                $table->foreignId('picked_up_by_user_id')
                    ->nullable()
                    ->after('picked_up_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'picked_up_by_user_id')) {
                $table->dropConstrainedForeignId('picked_up_by_user_id');
            }

            if (Schema::hasColumn('orders', 'picked_up_at')) {
                $table->dropColumn('picked_up_at');
            }

            if (Schema::hasColumn('orders', 'pickup_ready_at')) {
                $table->dropColumn('pickup_ready_at');
            }
        });
    }
};
