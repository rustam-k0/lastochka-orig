<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Флаг отключения автопересчёта цены кванта для отдельного товара
 * (LASTOCHKI-JAMI-11200). Индивидуальный квант товара — существующие
 * info_weight / info_weight_type_id.
 */
return new class extends Migration
{
  public function up(): void
  {
    Schema::table('products', function (Blueprint $table) {
      if (!Schema::hasColumn('products', 'quantum_auto_recalc')) {
        $table->boolean('quantum_auto_recalc')->default(true)->after('info_weight_type_id')
          ->comment('Автопересчёт цены кванта при обновлениях из 1С');
      }
    });
  }

  public function down(): void
  {
    Schema::table('products', function (Blueprint $table) {
      $table->dropColumn('quantum_auto_recalc');
    });
  }
};
