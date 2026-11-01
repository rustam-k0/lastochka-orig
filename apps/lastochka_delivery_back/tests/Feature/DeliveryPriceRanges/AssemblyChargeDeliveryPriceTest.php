<?php

namespace Tests\Feature\DeliveryPriceRanges;

use App\Models\Type;
use App\Models\User;
use App\Services\FcmNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * MID-280: «Списать оплату» сборщиком (модуль collector_payment) списывает сумму,
 * в которой доставка уже посчитана по снимку условий заказа.
 */
class AssemblyChargeDeliveryPriceTest extends TestCase
{
  use DatabaseTransactions, MakesGraphQLRequests, DeliveryPriceRangesFixtures;

  private const MUTATION = '
    mutation AssemblyChargePayment($id: Int!) {
      AssemblyChargePayment(id: $id) { code message }
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

    $this->shopId = $this->insertShop();
    $this->clientId = $this->insertClient();
    $this->collector = $this->insertCollector($this->shopId);
    $this->setCollectorPaymentModule(true);
  }

  public function test_settled_amount_uses_delivery_by_snapshot(): void
  {
    Http::fake(['*' => Http::response(['status' => 'ok', 'payment_status' => 'paid'], 200)]);

    // Оформлено 2 × 750 = 1500 → 100; собрано 5 шт = 3750 → бесплатно
    $orderId = $this->insertOrder($this->shopId, $this->clientId, [
      'uuid' => '880280',
      'payment_type_id' => Type::PAYMENT_ONLINE,
      'amount' => 1600,
      'delivery_price' => 100,
      'delivery_price_snapshot' => $this->snapshotJson(100),
    ]);
    $assemblyId = $this->insertAssembly($orderId, $this->collector->id);
    $this->insertAssemblyItem($assemblyId, $this->insertOrderItem($orderId, 750, 2), 5);

    $this->actingAs($this->collector, 'api');
    $this->graphQL(self::MUTATION, ['id' => $assemblyId])
      ->assertJson(['data' => ['AssemblyChargePayment' => ['code' => 200]]]);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/internal/orders/880280/settle-payment')
      && abs((float) $request['amount'] - 3750.0) < 0.01);

    $order = $this->orderRow($orderId);
    $this->assertSame(0, (int) $order->delivery_price);
    $this->assertSame(1, (int) $order->paid);
  }
}
