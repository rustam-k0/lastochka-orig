<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Справочник квантов весовых товаров на листовых 1С-категориях
 * (LASTOCHKI-JAMI-11200). Настройка реплицируется в 1c_parser событием
 * quantum_setting.quantum_updated, пересчёт цен выполняет парсер.
 */
return new class extends Migration
{
  public function up(): void
  {
    Schema::table('categories_1c', function (Blueprint $table) {
      if (!Schema::hasColumn('categories_1c', 'quantum_grams')) {
        $table->unsignedInteger('quantum_grams')->nullable()->after('position')
          ->comment('Квант продажи весовых товаров категории, граммы');
      }
      if (!Schema::hasColumn('categories_1c', 'quantum_auto_recalc')) {
        $table->boolean('quantum_auto_recalc')->default(true)->after('quantum_grams')
          ->comment('Автопересчёт цены кванта при обновлениях из 1С');
      }
    });
  }

  public function down(): void
  {
    Schema::table('categories_1c', function (Blueprint $table) {
      $table->dropColumn(['quantum_grams', 'quantum_auto_recalc']);
    });
  }
};
