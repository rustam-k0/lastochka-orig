<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Диапазоны стоимости доставки (MID-280): снимок условий доставки заказа
 * {base_price, ranges[], checkout_price}. NULL у заказов до выкладки: их
 * доставка пересчитывается прежней логикой.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'delivery_price_snapshot')) {
                $table->json('delivery_price_snapshot')->nullable()->after('delivery_cost')
                    ->comment('Снимок условий доставки при оформлении (MID-280)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'delivery_price_snapshot')) {
                $table->dropColumn('delivery_price_snapshot');
            }
        });
    }
};
