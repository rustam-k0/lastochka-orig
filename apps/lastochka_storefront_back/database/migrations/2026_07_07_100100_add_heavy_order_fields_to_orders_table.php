<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Признак «Тяжёлый заказ» — вес корзины превысил порог магазина при чекауте;
            // обновляется обратным событием order.updated после пересчёта на сборке.
            $table->boolean('is_heavy')->default(false)->after('items_count');
            // Число курьеров, задействованных в доставке (из delivery_back через
            // order.updated). >1 = совместная доставка («Заказ доставляют несколько курьеров»).
            $table->unsignedTinyInteger('couriers_count')->default(0)->after('is_heavy');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['is_heavy', 'couriers_count']);
        });
    }
};
