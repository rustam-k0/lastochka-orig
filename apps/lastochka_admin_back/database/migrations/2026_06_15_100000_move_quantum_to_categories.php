<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Перенос кванта весовых товаров на ВИТРИННЫЕ категории (LASTOCHKI-JAMI-11200, v2).
 *
 * Решение постановщика: размер кванта задаётся менеджером на обычной (витринной)
 * листовой категории, а не на 1С-категории. Пересчёт цены за квант выполняет
 * admin_back (он знает связь товар→витрина и сырую цену из 1С).
 *
 * - categories.quantum_grams — размер кванта в граммах (NULL = квант не задан);
 * - prices.price_per_kg переводим в decimal(12,2) — сырая цена 1С за кг с копейками
 *   (база для пересчёта цены кванта; раньше int — терял копейки).
 */
return new class extends Migration
{
  public function up(): void
  {
    Schema::table('categories', function (Blueprint $table) {
      if (!Schema::hasColumn('categories', 'quantum_grams')) {
        $table->unsignedInteger('quantum_grams')->nullable()->after('min_quantity')
          ->comment('Квант продажи весовых товаров категории, граммы (LASTOCHKI-JAMI-11200)');
      }
    });

    Schema::table('prices', function (Blueprint $table) {
      $table->decimal('price_per_kg', 12, 2)->nullable()->change();
    });
  }

  public function down(): void
  {
    Schema::table('categories', function (Blueprint $table) {
      $table->dropColumn('quantum_grams');
    });

    Schema::table('prices', function (Blueprint $table) {
      $table->integer('price_per_kg')->nullable()->change();
    });
  }
};
