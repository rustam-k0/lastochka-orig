<?php

namespace Tests\Feature\PriceTiers;

use App\GraphQL\Mutations\Assembly\AssemblyStartCooking;
use App\Models\Status;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Массовые подтверждения сборки (MID-308): AssemblyStartCooking и внутренний REST
 * /backend-delivery/order/assembly считают строку той же функцией, что ProductConfirm.
 */
class AssemblyBulkConfirmTierTest extends TestCase
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

  /**
   * Позиция с устаревшей ценой за единицу: количество 3, а price_during_order
   * остался от ступени «от 5». Только расчёт по снапшоту даёт верные 249.
   *
   * @return array{0:int,1:int,2:int} [orderId, assemblyId, assemblyItemId]
   */
  private function scenario(?string $snapshot, array $assemblyItemAttrs = [], float $unitPrice = 199): array
  {
    $orderId = $this->insertOrder($this->shopId, $this->clientId);
    $orderItemId = $this->insertOrderItem($orderId, $this->insertProduct(), [
      'quantity' => 3,
      'price' => $unitPrice,
      'price_during_order' => $unitPrice,
      'price_tiers' => $snapshot,
    ]);
    $assemblyId = $this->insertAssembly($orderId, $this->userId);
    $assemblyItemId = $this->insertAssemblyItem($assemblyId, $orderItemId, $assemblyItemAttrs);

    return [$orderId, $assemblyId, $assemblyItemId];
  }

  private function assemblyItemPrice(int $assemblyItemId): float
  {
    return (float) \DB::table('assembly_items')->where('id', $assemblyItemId)->value('price');
  }

  public function test_start_cooking_prices_line_by_tier(): void
  {
    $this->setPriceTiersFlag(true);
    [, $assemblyId, $assemblyItemId] = $this->scenario($this->snapshotJson(self::$LADDER_FROM_ONE));

    (new AssemblyStartCooking())(null, ['id' => $assemblyId]);

    $this->assertSame(747.0, $this->assemblyItemPrice($assemblyItemId), '3 × 249');
    $this->assertSame(
      Status::PRODUCT_ASSEMBLED,
      (int) \DB::table('assembly_items')->where('id', $assemblyItemId)->value('status_id')
    );
  }

  public function test_start_cooking_uses_quantity_of_assembly_item(): void
  {
    $this->setPriceTiersFlag(true);
    // Заказано 3 по 249, в строке сборки уже стоит изменённое количество 7 без цены
    [, $assemblyId, $assemblyItemId] = $this->scenario(
      $this->snapshotJson(self::$LADDER_FROM_ONE),
      ['quantity' => 7],
      249
    );

    (new AssemblyStartCooking())(null, ['id' => $assemblyId]);

    $this->assertSame(1393.0, $this->assemblyItemPrice($assemblyItemId), '7 × 199, а не 7 × 249');
  }

  public function test_start_cooking_keeps_price_already_set_by_product_confirm(): void
  {
    $this->setPriceTiersFlag(true);
    [, $assemblyId, $assemblyItemId] = $this->scenario(
      $this->snapshotJson(self::$LADDER_FROM_ONE),
      ['quantity' => 3, 'price' => 747, 'status_id' => Status::PRODUCT_ASSEMBLED]
    );

    (new AssemblyStartCooking())(null, ['id' => $assemblyId]);

    $this->assertSame(747.0, $this->assemblyItemPrice($assemblyItemId));
  }

  public function test_start_cooking_uses_existing_snapshot_even_when_flag_is_off(): void
  {
    $this->setPriceTiersFlag(false);
    [, $assemblyId, $assemblyItemId] = $this->scenario($this->snapshotJson(self::$LADDER_FROM_ONE));

    (new AssemblyStartCooking())(null, ['id' => $assemblyId]);

    $this->assertSame(747.0, $this->assemblyItemPrice($assemblyItemId), 'флаг гейтит только новые снапшоты');
  }

  public function test_start_cooking_without_snapshot_keeps_old_formula(): void
  {
    $this->setPriceTiersFlag(true);
    [, $assemblyId, $assemblyItemId] = $this->scenario(null);

    (new AssemblyStartCooking())(null, ['id' => $assemblyId]);

    $this->assertSame(597.0, $this->assemblyItemPrice($assemblyItemId), 'прежняя формула: 3 × 199');
  }

  public function test_rest_assembly_prices_line_by_tier(): void
  {
    config(['services.system.token' => 'test-token']);
    $this->setPriceTiersFlag(true);
    [$orderId, , $assemblyItemId] = $this->scenario($this->snapshotJson(self::$LADDER_FROM_ONE));

    $this->post('/backend-delivery/order/assembly', [
      'token' => 'test-token',
      'order_id' => $orderId,
    ])->assertStatus(200);

    $this->assertSame(747.0, $this->assemblyItemPrice($assemblyItemId));
  }

  public function test_rest_assembly_without_snapshot_keeps_old_formula(): void
  {
    config(['services.system.token' => 'test-token']);
    $this->setPriceTiersFlag(true);
    [$orderId, , $assemblyItemId] = $this->scenario(null);

    $this->post('/backend-delivery/order/assembly', [
      'token' => 'test-token',
      'order_id' => $orderId,
    ])->assertStatus(200);

    $this->assertSame(597.0, $this->assemblyItemPrice($assemblyItemId));
  }
}
