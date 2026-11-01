<?php

namespace Tests\Feature;

use App\Models\AssemblyItem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Сортировка товаров сборки по приоритету категорий магазина (LASTOCHKI-JAMI-11145).
 *
 * Приоритет (планограмма магазина) задаётся в админке per-shop в
 * shops_categories.position. Выдача assembly_items сборщику должна идти
 * в порядке приоритета категории товара; товары без приоритета — в конец.
 */
class AssemblyItemsPriorityTest extends TestCase
{
  use DatabaseTransactions;

  private int $shopId;
  private int $assemblyId;

  protected function setUp(): void
  {
    parent::setUp();

    $clientId = \DB::table('clients')->insertGetId([
      'is_authorized' => 1,
      'newsletter_subscribed' => 0,
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $this->shopId = \DB::table('shops')->insertGetId([
      'title' => 'Priority shop',
      'id_1c' => 999998,
      'default' => 0,
      'slot_max_orders' => 10,
      'slot_time' => 60,
      'is_popular' => 0,
      'is_pickup_enabled' => 0,
      'pickup_auto_cancel_enabled' => 0,
      'ignore_stock' => 0,
      'is_bonus_enabled' => 0,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $orderId = \DB::table('orders')->insertGetId([
      'shop_id' => $this->shopId,
      'uuid' => 'priority-' . uniqid(),
      'discount' => 0,
      'payment_type_id' => 1,
      'paid' => 0,
      'type_id' => 2,
      'client_id' => $clientId,
      'status_id' => 2,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $userId = \DB::table('users')->insertGetId([
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $this->assemblyId = \DB::table('assemblies')->insertGetId([
      'order_id' => $orderId,
      'user_id' => $userId,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  /**
   * Товар в категории с приоритетом $position (null — товар без категории).
   * Возвращает id assembly_item.
   */
  private function insertAssemblyItem(?int $position): int
  {
    $productId = \DB::table('products')->insertGetId([
      'title' => 'Priority product ' . uniqid(),
      'articul' => 'PRIO-' . uniqid(),
      'unit_type_id' => 1,
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    if ($position !== null) {
      $categoryId = \DB::table('categories')->insertGetId([
        'title' => 'Priority cat ' . uniqid(),
        'link' => 'prio-' . uniqid(),
        'created_at' => now(),
        'updated_at' => now(),
      ]);

      \DB::table('category_product')->insert([
        'product_id' => $productId,
        'category_id' => $categoryId,
      ]);

      \DB::table('shops_categories')->insert([
        'shop_id' => $this->shopId,
        'category_id' => $categoryId,
        'time_to_take' => 10,
        'position' => $position,
        'created_at' => now(),
        'updated_at' => now(),
      ]);
    }

    $orderId = \DB::table('assemblies')->where('id', $this->assemblyId)->value('order_id');

    $orderItemId = \DB::table('order_items')->insertGetId([
      'order_id' => $orderId,
      'product_id' => $productId,
      'quantity' => 1,
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    return (int) \DB::table('assembly_items')->insertGetId([
      'assembly_id' => $this->assemblyId,
      'order_item_id' => $orderItemId,
      'quantity' => 1,
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  private function orderedIds(): array
  {
    return AssemblyItem::query()
      ->where('assembly_id', $this->assemblyId)
      ->byCategoryPriority()
      ->pluck('assembly_items.id')
      ->all();
  }

  public function test_items_are_ordered_by_shop_category_position(): void
  {
    $itemLast = $this->insertAssemblyItem(7);
    $itemFirst = $this->insertAssemblyItem(1);
    $itemMiddle = $this->insertAssemblyItem(3);

    $this->assertSame([$itemFirst, $itemMiddle, $itemLast], $this->orderedIds());
  }

  public function test_items_without_priority_go_last(): void
  {
    $itemNoCategory = $this->insertAssemblyItem(null);
    $itemPrioritized = $this->insertAssemblyItem(2);

    $this->assertSame([$itemPrioritized, $itemNoCategory], $this->orderedIds());
  }

  public function test_falls_back_to_id_order_when_no_priorities(): void
  {
    $first = $this->insertAssemblyItem(null);
    $second = $this->insertAssemblyItem(null);

    $this->assertSame([$first, $second], $this->orderedIds());
  }
}
