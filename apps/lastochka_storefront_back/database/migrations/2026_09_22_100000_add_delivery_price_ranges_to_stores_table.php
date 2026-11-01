<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Диапазоны стоимости доставки (MID-280): ступени основной зоны магазина
 * [{from_amount, price}] из события магазина админки. NULL/[] = ступеней нет,
 * расчёт идёт по delivery_price и free_delivery_from, как раньше.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            if (! Schema::hasColumn('stores', 'delivery_price_ranges')) {
                $table->json('delivery_price_ranges')->nullable()->after('free_delivery_from')
                    ->comment('Ступени стоимости доставки от суммы заказа (MID-280)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            if (Schema::hasColumn('stores', 'delivery_price_ranges')) {
                $table->dropColumn('delivery_price_ranges');
            }
        });
    }
};
