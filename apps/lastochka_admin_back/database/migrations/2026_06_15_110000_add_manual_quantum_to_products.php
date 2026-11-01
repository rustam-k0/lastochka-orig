<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ручной квант весового товара (LASTOCHKI-JAMI-11200, v3).
 *
 * Размер кванта приоритетно задаётся витринной категорией
 * (categories.quantum_grams). Если ни одна категория товара квант не задаёт —
 * менеджер может проставить квант индивидуально товару: значение хранится в
 * products.manual_quantum_grams (граммы, NULL = ручной квант не задан).
 *
 * Эффективный квант = категорийный ?? ручной. По нему пересчитывается цена и
 * заполняется products.info_weight (output → storefront quantity_step).
 */
return new class extends Migration
{
  public function up(): void
  {
    Schema::table('products', function (Blueprint $table) {
      if (!Schema::hasColumn('products', 'manual_quantum_grams')) {
        $table->unsignedInteger('manual_quantum_grams')->nullable()->after('info_weight_type_id')
          ->comment('Ручной квант весового товара, граммы (fallback, если категория не задаёт квант) — LASTOCHKI-JAMI-11200');
      }
    });
  }

  public function down(): void
  {
    Schema::table('products', function (Blueprint $table) {
      $table->dropColumn('manual_quantum_grams');
    });
  }
};
