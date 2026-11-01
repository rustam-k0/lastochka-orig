<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Covering-индекс для SUM(quantity) по товару: remains_sum в списке товаров
 * и availableInShop-фильтры читают сумму остатков прямо из индекса, без
 * row lookup'ов по 4.5M-таблице (~1.5k строк-магазинов на товар).
 * Идемпотентно: проверка через information_schema (форки могли накатить руками).
 */
return new class extends Migration
{
  public function up(): void
  {
    if (!Schema::hasTable('remains')) {
      return;
    }

    $exists = DB::selectOne(
      "SELECT 1 FROM information_schema.statistics
       WHERE table_schema = DATABASE()
         AND table_name = 'remains'
         AND index_name = 'remains_product_quantity_index'
       LIMIT 1"
    );
    if ($exists !== null) {
      return;
    }

    DB::statement('CREATE INDEX remains_product_quantity_index ON remains (product_id, quantity)');
  }

  public function down(): void
  {
    if (!Schema::hasTable('remains')) {
      return;
    }

    $exists = DB::selectOne(
      "SELECT 1 FROM information_schema.statistics
       WHERE table_schema = DATABASE()
         AND table_name = 'remains'
         AND index_name = 'remains_product_quantity_index'
       LIMIT 1"
    );
    if ($exists === null) {
      return;
    }

    DB::statement('DROP INDEX remains_product_quantity_index ON remains');
  }
};
