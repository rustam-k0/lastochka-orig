<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Цены от количества (MID-308): снапшот лесенки в позиции заказа. Всё в порциях
 * и ценах за порцию: {base_price, base_price_old, base_is_eligible, tiers[]}.
 * NULL у позиций без лесенки: они считаются по прежним формулам.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'price_tiers')) {
                $table->json('price_tiers')->nullable()->after('eligible_for_bonus')
                    ->comment('Снапшот лесенки цен от количества (MID-308)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'price_tiers')) {
                $table->dropColumn('price_tiers');
            }
        });
    }
};
