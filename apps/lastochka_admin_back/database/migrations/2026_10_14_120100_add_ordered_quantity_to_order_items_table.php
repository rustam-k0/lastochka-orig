<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Сколько порций заказал клиент. AssemblyFinish переписывает order_items.quantity
 * фактом сборки (весовая 3 порции по 300 г, взвешено 528 г → 2), и «было» в
 * карточке заказа и заказанная сумма в аналитике считались от 2, а не от 3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'ordered_quantity')) {
                $table->integer('ordered_quantity')->nullable()->after('quantity')
                    ->comment('Порции, заказанные клиентом, до пересчёта по факту сборки; NULL — совпадает с quantity');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'ordered_quantity')) {
                $table->dropColumn('ordered_quantity');
            }
        });
    }
};
