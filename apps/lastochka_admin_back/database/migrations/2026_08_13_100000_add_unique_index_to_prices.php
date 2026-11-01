<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * UNIQUE-индекс prices(product_id, shop_id, type_id) — пререквизит bulk-записи
 * цен через upsert (подход A ТЗ price-batch-nplus1-optimization). Отсутствие
 * уникальности было латентной причиной дубль-строк цен (см. инцидент
 * дедупликации каталога 2026-08-12): processPrice создаёт строку при её
 * отсутствии, а наивный upsert без UNIQUE всегда INSERT'ит.
 *
 * Идемпотентна и защищена: если строк-дублей по ключу нет — навешивает UNIQUE;
 * если дубли есть или индекс уже стоит — пропускает с логом (на больших
 * таблицах ALTER держать в спокойном окне, см. раздел 11 ТЗ).
 */
return new class extends Migration
{
  private string $index = 'prices_product_shop_type_unique';

  public function up(): void
  {
    if ($this->indexExists()) {
      return;
    }

    $dups = DB::selectOne(
      'SELECT COUNT(*) AS c FROM (SELECT product_id, shop_id, type_id FROM prices '
      . 'GROUP BY product_id, shop_id, type_id HAVING COUNT(*) > 1) t'
    );

    if ((int) ($dups->c ?? 0) > 0) {
      DB::statement("SELECT 'skip prices unique: duplicate rows present, dedup first'");
      return;
    }

    DB::statement(
      "ALTER TABLE prices ADD UNIQUE INDEX {$this->index} (product_id, shop_id, type_id)"
    );
  }

  public function down(): void
  {
    if ($this->indexExists()) {
      DB::statement("ALTER TABLE prices DROP INDEX {$this->index}");
    }
  }

  private function indexExists(): bool
  {
    $row = DB::selectOne(
      'SELECT COUNT(*) AS c FROM information_schema.statistics '
      . 'WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
      ['prices', $this->index]
    );

    return (int) ($row->c ?? 0) > 0;
  }
};
