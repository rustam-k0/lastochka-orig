<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->boolean('eligible_for_bonus')->default(true)->after('status')
                ->comment('Снапшот «без скидки на момент покупки»: фиксируется при создании/появлении item и не меняется.');
        });

        // Backfill: выключаем флаг для позиций товаров, у которых на момент миграции
        // есть ручная скидка (price_old) или активная акция. Для уже доставленных
        // заказов это no-op (начисление уже произошло), для активных — приближённая
        // правда; перерасчёт применит актуальные значения через BonusService.
        if (Schema::hasTable('products')) {
            DB::table('order_items')
                ->whereIn('product_id', function ($q) {
                    $q->select('id')->from('products')->whereNotNull('price_old');
                })
                ->update(['eligible_for_bonus' => false]);

            if (Schema::hasTable('promotion_products') && Schema::hasTable('promotions')) {
                DB::table('order_items')
                    ->whereIn('product_id', function ($q) {
                        $q->select('promotion_products.product_id')
                            ->from('promotion_products')
                            ->join('promotions', 'promotions.id', '=', 'promotion_products.promotion_id')
                            ->where('promotions.is_active', true)
                            ->where('promotions.starts_at', '<=', now())
                            ->where('promotions.ends_at', '>=', now())
                            ->whereNull('promotions.deleted_at');
                    })
                    ->update(['eligible_for_bonus' => false]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('eligible_for_bonus');
        });
    }
};
