<?php

namespace Tests\Feature\DeliveryPriceRanges;

use App\Models\Role;
use App\Models\User;
use App\Services\FcmNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * MID-280: заказ, расщеплённый на подзаказы по зонам сборки. Доставка живёт на
 * родителе (у детей 0 и снимка нет): после сборки подзаказов родитель считает её
 * по своему снимку от суммы товаров детей до скидки (calculateParentTotals).
 */
class ParentOrderDeliveryPriceTest extends TestCase
{
  use DatabaseTransactions, MakesGraphQLRequests, DeliveryPriceRangesFixtures;

  private const MUTATION = '
    mutation AssemblyFinish($id: Int!, $packages: [PackageInput]) {
      AssemblyFinish(id: $id, packages: $packages) { code message }
    }
  ';

  private int $shopId;
  private int $clientId;
  private User $collector;

  protected function setUp(): void
  {
    parent::setUp();

    $this->mock(FcmNotificationService::class)
      ->shouldReceive('sendToUser')->andReturnNull()
      ->shouldReceive('sendToUsers')->andReturnNull();

    Role::findOrCreate('courier', 'api');

    $this->shopId = $this->insertShop();
    $this->clientId = $this->insertClient();
    $this->collector = $this->insertCollector($this->shopId);
    $this->setCollectorPaymentModule(false);
  }

  /**
   * Подзаказ с одной позицией. Возвращает [childId, assemblyId].
   *
   * @return array{0:int,1:int}
   */
  private function child(int $parentId, int $subNumber, float $unitPrice, int $ordered, int $collected): array
  {
    $childId = $this->insertOrder($this->shopId, $this->clientId, [
      'parent_order_id' => $parentId,
      'sub_number' => $subNumber,
      'uuid' => $this->orderRow($parentId)->uuid . '-' . $subNumber,
      'amount' => $unitPrice * $ordered,
      'origin_amount' => $unitPrice * $ordered,
    ]);
    $assemblyId = $this->insertAssembly($childId, $this->collector->id);
    $this->insertAssemblyItem($assemblyId, $this->insertOrderItem($childId, $unitPrice, $ordered), $collected);

    return [$childId, $assemblyId];
  }

  private function finish(int $assemblyId): void
  {
    $this->actingAs($this->collector, 'api');

    $this->graphQL(self::MUTATION, [
      'id' => $assemblyId,
      'packages' => [['type_id' => 1, 'quantity' => 1]],
    ])->assertJson(['data' => ['AssemblyFinish' => ['code' => 200]]]);
  }

  public function test_parent_delivery_follows_children_amount_by_snapshot(): void
  {
    // Оформлено 1000 + 500 = 1500 → 100; собрано 2000 + 1500 = 3500 → бесплатно
    $parentId = $this->insertOrder($this->shopId, $this->clientId, [
      'amount' => 1600, 'delivery_price' => 100, 'delivery_price_snapshot' => $this->snapshotJson(100),
    ]);
    [$childA, $assemblyA] = $this->child($parentId, 1, 1000, 1, 2);
    [$childB, $assemblyB] = $this->child($parentId, 2, 500, 1, 3);

    $this->finish($assemblyA);
    $this->finish($assemblyB);

    $parent = $this->orderRow($parentId);
    $this->assertSame(0, (int) $parent->delivery_price);
    $this->assertSame(3500.0, (float) $parent->amount);

    // Дети без снимка: их доставка 0 и остаётся 0
    $this->assertSame(0, (int) $this->orderRow($childA)->delivery_price);
    $this->assertSame(0, (int) $this->orderRow($childB)->delivery_price);
    $this->assertNull($this->orderRow($childA)->delivery_price_snapshot);
  }

  public function test_parent_delivery_is_capped_by_checkout_price(): void
  {
    // Прежний пересчёт родителя обнулил бы доставку по живому порогу 500
    $this->insertZone($this->shopId, 300, null, ['free_delivery_from' => 500]);

    // Оформлено 1000 + 500 = 1500 → 100; собрано 600 + 0 (отменено всё во второй зоне)
    $parentId = $this->insertOrder($this->shopId, $this->clientId, [
      'amount' => 1600, 'delivery_price' => 100, 'delivery_price_snapshot' => $this->snapshotJson(100),
    ]);
    [, $assemblyA] = $this->child($parentId, 1, 600, 1, 1);
    [, $assemblyB] = $this->child($parentId, 2, 300, 1, 1);

    $this->finish($assemblyA);
    $this->finish($assemblyB);

    $parent = $this->orderRow($parentId);
    // 600 + 300 = 900: по лесенке 200, не дороже оформления 100
    $this->assertSame(100, (int) $parent->delivery_price);
    $this->assertSame(1000.0, (float) $parent->amount);
  }
}
