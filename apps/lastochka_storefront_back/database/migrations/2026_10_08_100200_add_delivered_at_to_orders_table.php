<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Момент доставки заказа: сортировка автогруппы «Из истории заказов».
 * updated_at для этого не годится — его перезаписывает любое событие после
 * доставки. Для уже доставленных заказов бэкфилл из updated_at (точнее
 * восстановить нечем).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'delivered_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->timestamp('delivered_at')->nullable()->comment('Момент перехода заказа в delivered');
                $table->index(['user_id', 'status', 'delivered_at'], 'orders_user_status_delivered_at_index');
            });
        }

        DB::table('orders')
            ->where('status', 'delivered')
            ->whereNull('delivered_at')
            ->update(['delivered_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'delivered_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropIndex('orders_user_status_delivered_at_index');
                $table->dropColumn('delivered_at');
            });
        }
    }
};
