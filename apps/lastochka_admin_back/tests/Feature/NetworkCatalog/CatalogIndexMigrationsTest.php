<?php

namespace Tests\Feature\NetworkCatalog;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CatalogIndexMigrationsTest extends TestCase
{
  use DatabaseTransactions;

  private function indexExists(string $table, string $index): bool
  {
    return !empty(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]));
  }

  public function test_prices_has_shop_id_column_and_index(): void
  {
    $this->assertTrue(Schema::hasColumn('prices', 'shop_id'));
    $this->assertTrue($this->indexExists('prices', 'prices_product_shop_type_index'));
  }

  public function test_remains_has_product_shop_index(): void
  {
    $this->assertTrue($this->indexExists('remains', 'remains_product_shop_index'));
  }
}
