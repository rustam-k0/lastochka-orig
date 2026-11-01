<?php

namespace Tests\Feature\PriceTiers;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Страж схемы «цены от количества» (MID-308). Своих миграций у delivery_back нет:
 * таблицу price_tiers и колонку order_items.price_tiers создают миграции admin_back,
 * а локальная courier_test собирается командой `php artisan testing:prepare-db`
 * из рабочего дерева admin_back. Красный тест = тестовую БД собрали не с той ветки.
 */
class PriceTiersSchemaTest extends TestCase
{
  public function test_price_tiers_table_exists_with_contract_columns(): void
  {
    $this->assertTrue(Schema::hasTable('price_tiers'), 'нет таблицы price_tiers: пересобрать courier_test из admin_back feat/price-tiers');

    foreach (['product_id', 'shop_id', 'min_quantity', 'value'] as $column) {
      $this->assertTrue(Schema::hasColumn('price_tiers', $column), "нет колонки price_tiers.{$column}");
    }
  }

  public function test_order_items_has_price_tiers_and_price_old(): void
  {
    $this->assertTrue(Schema::hasColumn('order_items', 'price_tiers'), 'нет колонки order_items.price_tiers');
    $this->assertTrue(Schema::hasColumn('order_items', 'price_old'), 'нет колонки order_items.price_old');
    $this->assertTrue(Schema::hasColumn('order_items', 'is_eligible'), 'нет колонки order_items.is_eligible');
  }
}
