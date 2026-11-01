<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Время сборки и приоритет категорий магазина (LASTOCHKI-JAMI-11145).
 *
 * Исторически time_to_take/position жили в таблице shop_categories (мертва),
 * а живой pivot привязки магазин<->категория — shops_categories. Переносим
 * колонки в живую таблицу: её пишет SetShopCategoriesTime (admin_back)
 * и читают AssemblyTimeCalculator (slots-api/purchase_back) и delivery_back.
 */
return new class extends Migration
{
  public function up()
  {
    if (!Schema::hasColumn('shops_categories', 'time_to_take')) {
      Schema::table('shops_categories', function (Blueprint $table) {
        $table->integer('time_to_take')->default(0)->after('category_id');
      });
    }

    if (!Schema::hasColumn('shops_categories', 'position')) {
      Schema::table('shops_categories', function (Blueprint $table) {
        $table->integer('position')->default(0)->after('time_to_take');
      });
    }
  }

  public function down()
  {
    if (Schema::hasColumn('shops_categories', 'position')) {
      Schema::table('shops_categories', function (Blueprint $table) {
        $table->dropColumn('position');
      });
    }

    if (Schema::hasColumn('shops_categories', 'time_to_take')) {
      Schema::table('shops_categories', function (Blueprint $table) {
        $table->dropColumn('time_to_take');
      });
    }
  }
};
