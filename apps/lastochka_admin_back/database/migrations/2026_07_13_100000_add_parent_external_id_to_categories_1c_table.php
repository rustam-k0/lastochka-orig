<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Late-binding родителя 1С-категорий: 1С шлёт полный дамп НЕ в порядке
 * «родители раньше детей», а Category1cEventTransformer::resolveParent
 * резолвил parent только если тот уже существует — parent GUID из payload
 * терялся, и категория навсегда оставалась «корнем» (на mendev так осиротело
 * 1672 из 6206 категорий, дамп 2026-07-02).
 *
 * parent_external_id хранит GUID родителя из последнего события; когда
 * родитель приезжает, трансформер доцепляет ждущих детей (adoptWaitingChildren).
 * Паттерн зеркалит products.pending_category_1c_external_id + Category1cObserver.
 *
 * Backfill: для уже связанных строк GUID выводится из родителя (join).
 * Guard'ы колонки и индекса РАЗДЕЛЬНЫЕ: колонка и индекс — разные ALTER,
 * прерванный между ними прогон не должен навсегда оставить таблицу без индекса.
 */
return new class extends Migration
{
  private const INDEX = 'idx_categories_1c_shop_parent_ext';

  public function up(): void
  {
    Schema::table('categories_1c', function (Blueprint $table) {
      if (!Schema::hasColumn('categories_1c', 'parent_external_id')) {
        $table->string('parent_external_id', 64)->nullable()->after('parent_id')
          ->comment('GUID родителя из последнего события 1С (для late-binding, когда родитель ещё не приехал)');
      }
    });

    if (!$this->indexExists()) {
      Schema::table('categories_1c', function (Blueprint $table) {
        $table->index(['shop_id', 'parent_external_id'], self::INDEX);
      });
    }

    DB::statement('
      UPDATE categories_1c c
      JOIN categories_1c p ON p.id = c.parent_id
      SET c.parent_external_id = p.external_id
      WHERE c.parent_external_id IS NULL
    ');
  }

  public function down(): void
  {
    if ($this->indexExists()) {
      Schema::table('categories_1c', function (Blueprint $table) {
        $table->dropIndex(self::INDEX);
      });
    }

    Schema::table('categories_1c', function (Blueprint $table) {
      if (Schema::hasColumn('categories_1c', 'parent_external_id')) {
        $table->dropColumn('parent_external_id');
      }
    });
  }

  private function indexExists(): bool
  {
    return !empty(DB::select(
      'SELECT 1 FROM information_schema.statistics
       WHERE table_schema = DATABASE()
         AND table_name = ?
         AND index_name = ?
       LIMIT 1',
      ['categories_1c', self::INDEX]
    ));
  }
};
