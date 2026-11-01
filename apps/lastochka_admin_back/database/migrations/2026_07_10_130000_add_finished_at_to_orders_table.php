<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * orders.finished_at — момент фактического завершения заказа (доставлен/выдан).
     * Нужен вкладке «Статистика» карточки магазина: выручка за период считается
     * по дате завершения заказа, а не по created_at.
     *
     * Backfill исторических завершённых заказов (status_id = 5, ORDER_FINISHED):
     * COALESCE(picked_up_at, MAX(delivery_orders.finished_at), updated_at) —
     * берём наиболее осмысленную отметку завершения, доступную в БД.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'finished_at')) {
                $table->timestamp('finished_at')->nullable()->after('paid_at')
                    ->comment('Момент фактического завершения заказа (доставлен/выдан). Для вкладки «Статистика» — выручка за период по дате завершения.');
            }
        });

        // Backfill только для уже завершённых заказов, у которых отметка ещё не
        // проставлена. whereNull делает миграцию идемпотентной при повторном
        // прогоне. Данные пишем через DB::table (правило миграций admin_back).
        if (Schema::hasColumn('orders', 'finished_at')) {
            DB::table('orders')
                ->where('status_id', 5) // Status::ORDER_FINISHED
                ->whereNull('finished_at')
                ->update([
                    'finished_at' => DB::raw(
                        'COALESCE('
                        . 'picked_up_at, '
                        . '(SELECT MAX(dord.finished_at) FROM delivery_orders dord '
                        . 'WHERE dord.order_id = orders.id AND dord.deleted_at IS NULL), '
                        . 'updated_at'
                        . ')'
                    ),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'finished_at')) {
                $table->dropColumn('finished_at');
            }
        });
    }
};
