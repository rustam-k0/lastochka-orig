<?php

namespace Tests\Feature\PriceTiers;

use App\GraphQL\Mutations\Assembly\ProductConfirm;
use App\Models\Type;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * ProductConfirm с заменой (MID-308): цена товара-замены берётся по свежему
 * снапшоту из живой лесенки price_tiers магазина ЗАКАЗА и собранному количеству.
 */
class ProductConfirmReplacementTierTest extends TestCase
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
   * Позиция «3 шт исходного товара по 150» с предложенной заменой.
   *
   * @return int id строки сборки
   */
  private function assemblyItemWithReplacement(int $replacementProductId, int $quantity = 3): int
  {
    $orderId = $this->insertOrder($this->shopId, $this->clientId);
    $originProductId = $this->insertProduct();
    $this->insertPrice($originProductId, $this->shopId, 150);

    $orderItemId = $this->insertOrderItem($orderId, $originProductId, [
      'quantity' => $quantity,
      'price' => 150,
      'price_during_order' => 150,
    ]);

    $assemblyItemId = $this->insertAssemblyItem($this->insertAssembly($orderId, $this->userId), $orderItemId, [
      'type_id' => Type::PRODUCT_NOT_EXISTS,
    ]);

    \DB::table('replacement_products')->insert([
      'product_id' => $replacementProductId,
      'assembly_item_id' => $assemblyItemId,
      'selected' => 0,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    return $assemblyItemId;
  }

  private function pieceReplacement(bool $withLadder): int
  {
    $productId = $this->insertProduct();
    $this->insertPrice($productId, $this->shopId, 120);

    if ($withLadder) {
      $this->insertLadder($productId, $this->shopId, [[1, 120], [3, 100]]);
    }

    return $productId;
  }

  private function confirm(int $assemblyItemId, int $replacementProductId, array $args): object
  {
    (new ProductConfirm())->__invoke(null, array_merge([
      'assembly_item_id' => $assemblyItemId,
      'replacement_product_id' => $replacementProductId,
    ], $args));

    return \DB::table('assembly_items')->where('id', $assemblyItemId)->first();
  }

  public function test_piece_replacement_uses_fresh_ladder_of_order_shop(): void
  {
    $this->setPriceTiersFlag(true);
    $replacementId = $this->pieceReplacement(true);
    $assemblyItemId = $this->assemblyItemWithReplacement($replacementId);

    $row = $this->confirm($assemblyItemId, $replacementId, ['quantity' => 3]);

    $this->assertSame(3, (int) $row->quantity);
    $this->assertSame(300.0, (float) $row->price, '3 шт замены: ступень «от 3» = 100, а не обычные 120');
  }

  public function test_piece_replacement_below_threshold_uses_first_tier(): void
  {
    $this->setPriceTiersFlag(true);
    $replacementId = $this->pieceReplacement(true);
    $assemblyItemId = $this->assemblyItemWithReplacement($replacementId);

    $this->assertSame(240.0, (float) $this->confirm($assemblyItemId, $replacementId, ['quantity' => 2])->price);
  }

  public function test_replacement_ignores_ladder_of_another_shop(): void
  {
    $this->setPriceTiersFlag(true);
    $replacementId = $this->pieceReplacement(false);
    $this->insertLadder($replacementId, $this->insertShop(), [[1, 50]]);
    $assemblyItemId = $this->assemblyItemWithReplacement($replacementId);

    $this->assertSame(360.0, (float) $this->confirm($assemblyItemId, $replacementId, ['quantity' => 3])->price);
  }

  public function test_weight_replacement_uses_fresh_ladder_in_portions(): void
  {
    $this->setPriceTiersFlag(true);
    $replacementId = $this->insertProduct([
      'info_weight' => 200,
      'info_weight_type_id' => Type::INFO_WEIGHT_GRAM,
      'base_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM,
    ]);
    $this->insertPrice($replacementId, $this->shopId, 100);
    // «от 1 кг по 450 ₽ за кг» → «от 5 порций по 90 ₽»
    $this->insertLadder($replacementId, $this->shopId, [[1, 450]]);
    $assemblyItemId = $this->assemblyItemWithReplacement($replacementId, 5);

    $row = $this->confirm($assemblyItemId, $replacementId, ['actual_weight' => 960]);

    $this->assertSame(960, (int) $row->actual_weight);
    $this->assertSame(432.0, (float) $row->price, '960 г → 5 порций → 90 × 960 / 200');
  }

  public function test_flag_off_keeps_old_formula(): void
  {
    $this->setPriceTiersFlag(false);
    $replacementId = $this->pieceReplacement(true);
    $assemblyItemId = $this->assemblyItemWithReplacement($replacementId);

    $this->assertSame(360.0, (float) $this->confirm($assemblyItemId, $replacementId, ['quantity' => 3])->price, 'новые снапшоты при выключенном модуле не строятся: прежняя формула 3 × 120');
  }

  public function test_replacement_without_ladder_keeps_old_formula(): void
  {
    $this->setPriceTiersFlag(true);
    $replacementId = $this->pieceReplacement(false);
    $assemblyItemId = $this->assemblyItemWithReplacement($replacementId);

    $this->assertSame(360.0, (float) $this->confirm($assemblyItemId, $replacementId, ['quantity' => 3])->price);
  }
}
