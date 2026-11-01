<?php

namespace Tests\Feature\PriceTiers;

use App\GraphQL\Mutations\Assembly\ProductConfirm;
use App\Models\Type;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * ProductConfirm, весовой товар (MID-308). Килограммовый товар с квантом 200 г:
 * порция 200 г стоит 100 ₽, от 5 порций (1 кг) 90 ₽. Порции по факту =
 * max(1, round(actual_weight / 200)): недовес в пределах половины шага ступень
 * не срывает. Цена строки = цена ступени × actual_weight / 200.
 */
class ProductConfirmWeightTierTest extends TestCase
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

  private function weightSnapshot(): string
  {
    return $this->snapshotJson([['min_quantity' => 5, 'price' => 90]], 100.0, null, true);
  }

  private function assemblyItemFor(int $portions, float $unitPrice, ?string $snapshot): int
  {
    $orderId = $this->insertOrder($this->shopId, $this->clientId);
    $productId = $this->insertProduct([
      'info_weight' => 200,
      'info_weight_type_id' => Type::INFO_WEIGHT_GRAM,
      'base_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM,
    ]);
    $orderItemId = $this->insertOrderItem($orderId, $productId, [
      'quantity' => $portions,
      'price' => $unitPrice,
      'price_during_order' => $unitPrice,
      'price_tiers' => $snapshot,
    ]);

    return $this->insertAssemblyItem($this->insertAssembly($orderId, $this->userId), $orderItemId);
  }

  private function confirmWeight(int $assemblyItemId, int $actualWeight): object
  {
    (new ProductConfirm())->__invoke(null, [
      'assembly_item_id' => $assemblyItemId,
      'actual_weight' => $actualWeight,
    ]);

    return \DB::table('assembly_items')->where('id', $assemblyItemId)->first();
  }

  public function test_underweight_within_half_step_keeps_tier(): void
  {
    $this->setPriceTiersFlag(true);
    $assemblyItemId = $this->assemblyItemFor(5, 90, $this->weightSnapshot());

    $row = $this->confirmWeight($assemblyItemId, 960);

    $this->assertSame(960, (int) $row->actual_weight);
    $this->assertNull($row->quantity);
    $this->assertSame(432.0, (float) $row->price, '960 г → 5 порций → ступень 90: 90 × 960 / 200');
  }

  public function test_underweight_beyond_half_step_drops_to_base_price(): void
  {
    $this->setPriceTiersFlag(true);
    $assemblyItemId = $this->assemblyItemFor(5, 90, $this->weightSnapshot());

    $this->assertSame(440.0, (float) $this->confirmWeight($assemblyItemId, 880)->price, '880 г → 4 порции → обычная цена 100: 100 × 880 / 200');
  }

  public function test_overweight_reaches_cheaper_tier(): void
  {
    $this->setPriceTiersFlag(true);
    $assemblyItemId = $this->assemblyItemFor(4, 100, $this->weightSnapshot());

    $this->assertSame(468.0, (float) $this->confirmWeight($assemblyItemId, 1040)->price, '1040 г → 5 порций → ступень 90: 90 × 1040 / 200');
  }

  public function test_existing_snapshot_is_used_even_when_flag_is_off(): void
  {
    $this->setPriceTiersFlag(false);
    $assemblyItemId = $this->assemblyItemFor(5, 90, $this->weightSnapshot());

    $this->assertSame(440.0, (float) $this->confirmWeight($assemblyItemId, 880)->price, 'флаг гейтит только новые снапшоты');
  }

  public function test_item_without_snapshot_keeps_old_formula(): void
  {
    $this->setPriceTiersFlag(true);
    $assemblyItemId = $this->assemblyItemFor(5, 90, null);

    $this->assertSame(396.0, (float) $this->confirmWeight($assemblyItemId, 880)->price, 'прежняя формула: price_during_order × вес / шаг');
  }
}
