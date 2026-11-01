<?php

namespace Tests\Feature\PriceTiers;

use App\GraphQL\Mutations\Assembly\ProductConfirm;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * ProductConfirm, штучный товар (MID-308): сборщик положил меньше или больше
 * заказанного, цена за единицу берётся по ступени для СОБРАННОГО количества из
 * снапшота позиции. assembly_items.price = итог строки.
 */
class ProductConfirmPieceTierTest extends TestCase
{
  use DatabaseTransactions, PriceTiersFixtures;

  private int $shopId;
  private int $clientId;
  private int $userId;

  protected function setUp(): void
  {
    parent::setUp();

    $this->shopId = $this->insertShop();
    $this->clientId = $this->insertClient();
    $this->userId = $this->insertUser();
  }

  /** Позиция «заказано $quantity по $unitPrice» + строка сборки. Возвращает id строки сборки. */
  private function assemblyItemFor(int $quantity, float $unitPrice, ?string $snapshot): int
  {
    $orderId = $this->insertOrder($this->shopId, $this->clientId);
    $orderItemId = $this->insertOrderItem($orderId, $this->insertProduct(), [
      'quantity' => $quantity,
      'price' => $unitPrice,
      'price_during_order' => $unitPrice,
      'price_tiers' => $snapshot,
    ]);

    return $this->insertAssemblyItem($this->insertAssembly($orderId, $this->userId), $orderItemId);
  }

  private function confirm(int $assemblyItemId, int $quantity): object
  {
    (new ProductConfirm())->__invoke(null, [
      'assembly_item_id' => $assemblyItemId,
      'quantity' => $quantity,
    ]);

    return \DB::table('assembly_items')->where('id', $assemblyItemId)->first();
  }

  public function test_five_to_three_moves_to_more_expensive_tier(): void
  {
    $this->setPriceTiersFlag(true);
    $assemblyItemId = $this->assemblyItemFor(5, 199, $this->snapshotJson(self::$LADDER_FROM_ONE));

    $row = $this->confirm($assemblyItemId, 3);

    $this->assertSame(3, (int) $row->quantity);
    $this->assertSame(747.0, (float) $row->price, '3 × 249, а не 3 × 199');
  }

  public function test_three_to_seven_moves_to_cheaper_tier(): void
  {
    $this->setPriceTiersFlag(true);
    $assemblyItemId = $this->assemblyItemFor(3, 249, $this->snapshotJson(self::$LADDER_FROM_ONE));

    $row = $this->confirm($assemblyItemId, 7);

    $this->assertSame(7, (int) $row->quantity);
    $this->assertSame(1393.0, (float) $row->price, '7 × 199, а не 7 × 249');
  }

  public function test_unchanged_quantity_keeps_order_price(): void
  {
    $this->setPriceTiersFlag(true);
    $assemblyItemId = $this->assemblyItemFor(5, 199, $this->snapshotJson(self::$LADDER_FROM_ONE));

    $this->assertSame(995.0, (float) $this->confirm($assemblyItemId, 5)->price);
  }

  public function test_quantity_below_first_threshold_falls_back_to_base_price(): void
  {
    $this->setPriceTiersFlag(true);
    // Лесенка «от 2»: обычная цена 269 живёт в base_price снапшота
    $snapshot = $this->snapshotJson([
      ['min_quantity' => 2, 'price' => 249],
      ['min_quantity' => 5, 'price' => 199],
    ], 269.0, 309.0, false);
    $assemblyItemId = $this->assemblyItemFor(2, 249, $snapshot);

    $this->assertSame(269.0, (float) $this->confirm($assemblyItemId, 1)->price);
  }

  public function test_existing_snapshot_is_used_even_when_flag_is_off(): void
  {
    // Флаг курьерки гейтит только НОВЫЕ снапшоты: позиция со снапшотом витрины
    // уже посчитана по ступени, и сборка обязана считать её так же
    $this->setPriceTiersFlag(false);
    $assemblyItemId = $this->assemblyItemFor(5, 199, $this->snapshotJson(self::$LADDER_FROM_ONE));

    $this->assertSame(747.0, (float) $this->confirm($assemblyItemId, 3)->price);
  }

  public function test_item_without_snapshot_keeps_old_formula(): void
  {
    $this->setPriceTiersFlag(true);
    $assemblyItemId = $this->assemblyItemFor(5, 199, null);

    $this->assertSame(597.0, (float) $this->confirm($assemblyItemId, 3)->price, 'прежняя формула: price_during_order × quantity');
  }
}
