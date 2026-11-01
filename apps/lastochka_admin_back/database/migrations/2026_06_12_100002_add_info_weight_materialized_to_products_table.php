<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Флаг происхождения шага количества (LASTOCHKI-JAMI-11200):
 * true — info_weight материализован из категорийного кванта (событие парсера),
 * false — задан вручную в карточке товара.
 *
 * Нужен, чтобы при снятии кванта категории reset-событие очищало
 * материализованные значения, но self-heal защищал ручные.
 */
return new class extends Migration
{
  public function up(): void
  {
    Schema::table('products', function (Blueprint $table) {
      if (!Schema::hasColumn('products', 'info_weight_materialized')) {
        $table->boolean('info_weight_materialized')->default(false)->after('quantum_auto_recalc');
      }
    });
  }

  public function down(): void
  {
    Schema::table('products', function (Blueprint $table) {
      $table->dropColumn('info_weight_materialized');
    });
  }
};
