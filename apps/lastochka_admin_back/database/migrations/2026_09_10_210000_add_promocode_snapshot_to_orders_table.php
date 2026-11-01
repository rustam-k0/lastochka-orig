<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Снапшот промокода заказа: тип (fixed/percent) и номинал.
     *
     * До этого курьерка знала только discount_percent, которого у
     * фиксированного промокода нет. После сборки скидка пересчитывалась
     * вслепую: у подзаказов она обнулялась, у одиночных заказов
     * масштабировалась пропорционально составу — витрина же держит
     * min(номинал, сумма товаров). Прод-заказы 200 и 207 (2026-09-10)
     * потеряли 300 ₽ скидки, чек ушёл на завышенную сумму.
     *
     * Guard — колонки могли быть созданы другим форком/веткой
     * (общая БД docker-модулей).
     *
     * @return void
     */
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'promocode_type')) {
                $table->string('promocode_type', 16)->nullable()->after('promocode_code');
            }

            if (! Schema::hasColumn('orders', 'promocode_value')) {
                $table->decimal('promocode_value', 10, 2)->nullable()->after('promocode_type');
            }
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach (['promocode_value', 'promocode_type'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
