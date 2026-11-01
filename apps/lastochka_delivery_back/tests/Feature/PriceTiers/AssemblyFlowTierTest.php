<?php

namespace Tests\Feature\PriceTiers;

use App\GraphQL\Mutations\Assembly\AssemblyFinish;
use App\GraphQL\Mutations\Assembly\ProductConfirm;
use App\Models\Assembly;
use App\Models\Order;
use App\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Сквозной сценарий сборки (MID-308): сборщик положил 3 вместо 5, цена за единицу,
 * итог строки, скидка по промокоду и событие заказа согласованы между собой.
 */
class AssemblyFlowTierTest extends TestCase
{
  use DatabaseTransactions, PriceTiersFixtures;

  /**
   * Заказ: 5 × 199 (лесенка 279/249/199) + 1 × 1000, промокод 10% на eligible-позиции.
   *
   * @return array{0:int,1:int,2:int} [orderId, assemblyId, tieredItemId]
   */
  private function runAssembly(bool $withSnapshot = true): array
  {
    $shopId = $this->insertShop();
    $orderId = $this->insertOrder($shopId, $this->insertClient(), [
      'amount' => 1895,
      'items_subtotal' => 1995,
      'discount' => 100,
      'discount_percent' => 10,
      'promocode_code' => 'TIER10',
      'promocode_type' => 'percent',
      'promocode_value' => 10,
      'eligible_amount' => 1000,
      'slot_start_date' => now(),
      'slot_end_date' => now(),
    ]);
    $assemblyId = $this->insertAssembly($orderId, $this->insertUser());

    $tieredItemId = $this->insertOrderItem($orderId, $this->insertProduct(), [
      'quantity' => 5, 'price' => 199, 'price_during_order' => 199,
      'price_old' => 279, 'is_eligible' => 0,
      'price_tiers' => $withSnapshot ? $this->snapshotJson(self::$LADDER_FROM_ONE) : null,
    ]);
    $regularItemId = $this->insertOrderItem($orderId, $this->insertProduct(), [
      'quantity' => 1, 'price' => 1000, 'price_during_order' => 1000,
    ]);

    $tieredAssemblyItemId = $this->insertAssemblyItem($assemblyId, $tieredItemId);
    $regularAssemblyItemId = $this->insertAssemblyItem($assemblyId, $regularItemId);

    (new ProductConfirm())->__invoke(null, ['assembly_item_id' => $tieredAssemblyItemId, 'quantity' => 3]);
    (new ProductConfirm())->__invoke(null, ['assembly_item_id' => $regularAssemblyItemId, 'quantity' => 1]);

    AssemblyFinish::assemblyFinish(Assembly::findOrFail($assemblyId), false);

    return [$orderId, $assemblyId, $tieredItemId];
  }

  /** @return array<string, mixed> */
  private function event(int $orderId): array
  {
    return app(OrderEventTransformer::class)->toEvent(
      Order::findOrFail($orderId)->load(['orderItems.product', 'orderItems.originProduct', 'orderItems.assemblyItem'])
    );
  }

  public function test_three_instead_of_five_is_consistent_from_confirm_to_event(): void
  {
    $this->setPriceTiersFlag(true);

    [$orderId, , $tieredItemId] = $this->runAssembly();

    $order = \DB::table('orders')->where('id', $orderId)->first();
    $this->assertSame(1747.0, (float) $order->items_subtotal, '3 × 249 + 1000');
    $this->assertSame(100.0, (float) $order->discount, 'позиция осталась на скидочной ступени: промокод только на 1000');
    $this->assertSame(1647.0, (float) $order->amount);

    $event = $this->event($orderId);
    $item = collect($event['items'])->firstWhere('id', (string) $tieredItemId);

    $this->assertSame(3, $item['quantity']);
    $this->assertSame(249.0, $item['unit_price']);
    $this->assertSame(747.0, $item['line_total']);
    $this->assertSame(279.0, $item['unit_price_old']);
    $this->assertCount(3, $item['price_tiers']['tiers'], 'ключ несёт любая позиция с непустой колонкой');
    $this->assertSame(1647.0, $event['grand_total']);
    $this->assertEqualsWithDelta(
      $event['grand_total'] + $event['discount_total'],
      collect($event['items'])->sum('line_total'),
      0.001,
      'Σ line_total = grand_total + скидка (доставки и сборки в заказе нет)'
    );
  }

  public function test_flag_off_with_existing_snapshot_gives_the_same_result(): void
  {
    // Позиция со снапшотом, модуль выключен, сборщик подтверждает 3 из 5: цена
    // пересчитана по снапшоту на всей цепочке
    $this->setPriceTiersFlag(false);

    [$orderId, , $tieredItemId] = $this->runAssembly();

    $order = \DB::table('orders')->where('id', $orderId)->first();
    $this->assertSame(1747.0, (float) $order->items_subtotal);
    $this->assertSame(1647.0, (float) $order->amount);

    $item = collect($this->event($orderId)['items'])->firstWhere('id', (string) $tieredItemId);
    $this->assertSame(249.0, $item['unit_price']);
    $this->assertSame(747.0, $item['line_total']);
    $this->assertSame(279.0, $item['unit_price_old']);
  }

  public function test_item_without_snapshot_gives_legacy_numbers_and_legacy_payload(): void
  {
    $this->setPriceTiersFlag(true);

    [$orderId, , $tieredItemId] = $this->runAssembly(false);

    $order = \DB::table('orders')->where('id', $orderId)->first();
    $this->assertSame(1597.0, (float) $order->items_subtotal, 'прежняя формула: 3 × 199 + 1000');
    $this->assertSame(1497.0, (float) $order->amount);

    $item = collect($this->event($orderId)['items'])->firstWhere('id', (string) $tieredItemId);

    $this->assertSame(199.0, $item['unit_price']);
    $this->assertSame(597.0, $item['line_total']);
    $this->assertArrayNotHasKey('unit_price_old', $item);
    $this->assertArrayNotHasKey('price_tiers', $item);
  }
}
