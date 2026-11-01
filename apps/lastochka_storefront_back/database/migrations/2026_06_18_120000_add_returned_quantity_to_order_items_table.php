<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Возврат товара курьером при доставке (tg-14265). Приходит в order.updated
     * из delivery_back; используется для отображения «вернул N» в заказе.
     */
    public function up(): void
    {
        if (Schema::hasTable('order_items') && !Schema::hasColumn('order_items', 'returned_quantity')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->integer('returned_quantity')->nullable()->default(0)->after('quantity');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('order_items', 'returned_quantity')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->dropColumn('returned_quantity');
            });
        }
    }
};
