<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Снимок условий доставки в заказе (MID-280).
 *
 * JSON {base_price, ranges: [{from_amount, price}], checkout_price}: лесенка
 * зоны на момент оформления и доставка, посчитанная при оформлении. Приходит
 * из события заказа витрины (ключ delivery_price_snapshot), при смене магазина
 * заказа снимок пересобирает delivery_back. После сборки и возврата у двери
 * доставка = min(ступень по фактической сумме товаров, checkout_price).
 * NULL — снимка нет (заказ до выкладки), действует прежний расчёт.
 *
 * Общая БД курьерских модулей: колонку читают и пишут delivery_back и
 * purchase_back. Guard — колонку мог создать другой форк/ветка.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'delivery_price_snapshot')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->json('delivery_price_snapshot')->nullable()->after('delivery_price')
                ->comment('Снимок условий доставки при оформлении (MID-280); NULL — снимка нет');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('orders', 'delivery_price_snapshot')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('delivery_price_snapshot');
        });
    }
};
