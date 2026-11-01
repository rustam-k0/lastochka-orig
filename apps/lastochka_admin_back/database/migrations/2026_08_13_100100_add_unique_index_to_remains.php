<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * UNIQUE-индекс remains(product_id, shop_id) — пререквизит bulk-записи остатков
 * через upsert (тот же подход, что для prices). Идемпотентна, с предпроверкой
 * дубль-строк; на больших таблицах ALTER держать в спокойном окне (раздел 11 ТЗ).
 */
return new class extends Migration
{
  private string $index = 'remains_product_shop_unique';

  public function up(): void
  {
    if ($this->indexExists()) {
      return;
    }

    $dups = DB::selectOne(
      'SELECT COUNT(*) AS c FROM (SELECT product_id, shop_id FROM remains '
      . 'GROUP BY product_id, shop_id HAVING COUNT(*) > 1) t'
    );

    if ((int) ($dups->c ?? 0) > 0) {
      DB::statement("SELECT 'skip remains unique: duplicate rows present, dedup first'");
      return;
    }

    DB::statement(
      "ALTER TABLE remains ADD UNIQUE INDEX {$this->index} (product_id, shop_id)"
    );
  }

  public function down(): void
  {
    if ($this->indexExists()) {
      DB::statement("ALTER TABLE remains DROP INDEX {$this->index}");
    }
  }

  private function indexExists(): bool
  {
    $row = DB::selectOne(
      'SELECT COUNT(*) AS c FROM information_schema.statistics '
      . 'WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
      ['remains', $this->index]
    );

    return (int) ($row->c ?? 0) > 0;
  }
};
