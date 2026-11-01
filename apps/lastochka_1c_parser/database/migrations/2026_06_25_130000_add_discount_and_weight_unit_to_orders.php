<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Выгрузка заказа в 1С: скидка и весовые позиции.
 *
 * - orders.discount — скидка по заказу (промокод), выгружается отдельной
 *   позицией «Скидка по заказу», чтобы сумма позиций сходилась с суммой
 *   документа (total = grand_total уже со скидкой).
 * - order_items.actual_weight_grams / base_unit_code — фактический вес и
 *   единица измерения (166=кг, 796=шт). Нужны, чтобы весовой товар уходил в 1С
 *   как «кг × цена за кг», а не «1 шт». Колонки могли остаться в БД от
 *   откаченной quantum-фичи (LASTOCHKI-JAMI-11200) — потому через hasColumn-guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'discount')) {
                $table->decimal('discount', 15, 2)->default(0)->after('total')
                    ->comment('Скидка по заказу (промокод)');
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'actual_weight_grams')) {
                $table->unsignedInteger('actual_weight_grams')->nullable()->after('total')
                    ->comment('Фактический вес собранной позиции, граммы');
            }
            if (! Schema::hasColumn('order_items', 'base_unit_code')) {
                $table->string('base_unit_code', 8)->nullable()->after('actual_weight_grams')
                    ->comment('Код базовой единицы ОКЕИ: 166=кг, 796=шт');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'discount')) {
                $table->dropColumn('discount');
            }
        });

        // actual_weight_grams / base_unit_code не удаляем в down — они могли
        // существовать до этой миграции (рудимент quantum-фичи).
    }
};
