<?php

namespace Tests\Feature\DeliveryPriceRanges;

use App\Models\Role;
use App\Models\Status;
use App\Models\User;
use App\Services\FcmNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * MID-280: доставка после завершения сборки (мутация AssemblyFinish) считается по
 * снимку условий заказа: ступень по фактической сумме товаров до промокода, не
 * дороже цены оформления. Лесенка снимка: цена зоны 200, от 1000 → 100, от 3000 → 0.
 */
class AssemblyFinishDeliveryPriceTest extends TestCase
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

    // Пуш курьерам после сборки ищет роль courier
    Role::findOrCreate('courier', 'api');

    $this->shopId = $this->insertShop();
    $this->clientId = $this->insertClient();
    $this->collector = $this->insertCollector($this->shopId);
    $this->setCollectorPaymentModule(false);
  }

  private function finish(int $assemblyId): void
  {
    $this->actingAs($this->collector, 'api');

    $this->graphQL(self::MUTATION, [
      'id' => $assemblyId,
      'packages' => [['type_id' => 1, 'quantity' => 1]],
    ])->assertJson(['data' => ['AssemblyFinish' => ['code' => 200]]]);
  }

  public function test_amount_grew_to_free_range(): void
  {
    // Оформлено 2 × 750 = 1500 → доставка 100; собрано 5 шт = 3750 → бесплатно
    $orderId = $this->insertOrder($this->shopId, $this->clientId, [
      'amount' => 1600, 'delivery_price' => 100, 'delivery_price_snapshot' => $this->snapshotJson(100),
    ]);
    $assemblyId = $this->insertAssembly($orderId, $this->collector->id);
    $this->insertAssemblyItem($assemblyId, $this->insertOrderItem($orderId, 750, 2), 5);

    $this->finish($assemblyId);

    $order = $this->orderRow($orderId);
    $this->assertSame(0, (int) $order->delivery_price);
    $this->assertSame(3750.0, (float) $order->amount);
  }

  public function test_amount_dropped_keeps_checkout_price_and_ignores_live_zone(): void
  {
    // Живая зона магазина с порогом 400 обнулила бы доставку прежним пересчётом:
    // у заказа со снимком она не участвует
    $this->insertZone($this->shopId, 300, null, ['free_delivery_from' => 400]);

    // Оформлено 1000 + 500 = 1500 → 100; сборщик отменил позицию на 1000 → 500:
    // по лесенке 200, но не дороже оформления
    $orderId = $this->insertOrder($this->shopId, $this->clientId, [
      'amount' => 1600, 'delivery_price' => 100, 'delivery_price_snapshot' => $this->snapshotJson(100),
    ]);
    $assemblyId = $this->insertAssembly($orderId, $this->collector->id);
    $this->insertAssemblyItem($assemblyId, $this->insertOrderItem($orderId, 1000), 1, Status::PRODUCT_CANCELED);
    $this->insertAssemblyItem($assemblyId, $this->insertOrderItem($orderId, 500), 1);

    $this->finish($assemblyId);

    $order = $this->orderRow($orderId);
    $this->assertSame(100, (int) $order->delivery_price);
    $this->assertSame(600.0, (float) $order->amount);
  }

  public function test_lower_bound_of_range_is_inclusive(): void
  {
    // Оформлено 3 × 250 = 750 → 200; собрано 4 шт = ровно 1000 → 100
    $orderId = $this->insertOrder($this->shopId, $this->clientId, [
      'amount' => 950, 'delivery_price' => 200, 'delivery_price_snapshot' => $this->snapshotJson(200),
    ]);
    $assemblyId = $this->insertAssembly($orderId, $this->collector->id);
    $this->insertAssemblyItem($assemblyId, $this->insertOrderItem($orderId, 250, 3), 4);

    $this->finish($assemblyId);

    $order = $this->orderRow($orderId);
    $this->assertSame(100, (int) $order->delivery_price);
    $this->assertSame(1100.0, (float) $order->amount);
  }

  public function test_range_is_chosen_by_amount_before_promocode(): void
  {
    // Прежний пересчёт сравнивал порог с суммой ПОСЛЕ скидки (3000 − 300 < 3000)
    $this->insertZone($this->shopId, 200, null, ['free_delivery_from' => 3000]);

    $orderId = $this->insertOrder($this->shopId, $this->clientId, [
      'amount' => 2800, 'delivery_price' => 100, 'discount' => 300,
      'promocode_type' => 'fixed', 'promocode_value' => 300,
      'delivery_price_snapshot' => $this->snapshotJson(100),
    ]);
    $assemblyId = $this->insertAssembly($orderId, $this->collector->id);
    $this->insertAssemblyItem($assemblyId, $this->insertOrderItem($orderId, 1000, 3), 3);

    $this->finish($assemblyId);

    $order = $this->orderRow($orderId);
    $this->assertSame(0, (int) $order->delivery_price, 'товары 3000 до промокода: бесплатно');
    $this->assertSame(300, (int) $order->discount);
    $this->assertSame(2700.0, (float) $order->amount);
  }

  public function test_order_without_snapshot_keeps_previous_zone_threshold(): void
  {
    // Страж прежнего поведения (зелёный и до правки): заказ до выкладки MID-280
    $this->insertZone($this->shopId, 300, null, ['free_delivery_from' => 500]);

    $orderId = $this->insertOrder($this->shopId, $this->clientId, ['amount' => 900, 'delivery_price' => 300]);
    $assemblyId = $this->insertAssembly($orderId, $this->collector->id);
    $this->insertAssemblyItem($assemblyId, $this->insertOrderItem($orderId, 600), 1);

    $this->finish($assemblyId);

    $order = $this->orderRow($orderId);
    $this->assertSame(0, (int) $order->delivery_price);
    $this->assertSame(600.0, (float) $order->amount);
  }
}
