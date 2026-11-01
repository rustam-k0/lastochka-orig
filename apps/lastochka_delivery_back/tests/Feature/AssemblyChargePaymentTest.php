<?php

namespace Tests\Feature;

use App\Models\Assembly;
use App\Models\Order;
use App\Models\Role;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use App\Services\FcmNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Мутация AssemblyChargePayment («Списать оплату», модуль collector_payment).
 *
 * Сборщик в мобилке жмёт «Списать оплату»: мутация пересчитывает итог сборки
 * (AssemblyFinish::assemblyFinish) и синхронно дёргает storefront через
 * StorefrontPaymentClient. Семантика ответов storefront: 200 успех;
 * 402 доплата не прошла; 5xx/сеть — транзиентный сбой.
 */
class AssemblyChargePaymentTest extends TestCase
{
  use DatabaseTransactions, MakesGraphQLRequests;

  private int $shopId;
  private int $clientId;
  private User $collector;

  private const MUTATION = '
    mutation AssemblyChargePayment($id: Int!) {
      AssemblyChargePayment(id: $id) { code message }
    }
  ';

  protected function setUp(): void
  {
    parent::setUp();

    // FCM не должен ходить наружу (используется в соседних флоу сборки).
    $this->mock(FcmNotificationService::class)
      ->shouldReceive('sendToUser')->andReturnNull()
      ->shouldReceive('sendToUsers')->andReturnNull();

    $this->shopId = \DB::table('shops')->insertGetId([
      'title' => 'Charge shop',
      'id_1c' => random_int(900000, 999999),
      'default' => 0,
      'slot_max_orders' => 10,
      'slot_time' => 60,
      'is_popular' => 0,
      'is_pickup_enabled' => 0,
      'pickup_auto_cancel_enabled' => 0,
      'ignore_stock' => 1,
      'is_bonus_enabled' => 0,
      'bonus_spend_percent' => 0,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    // orders.client_id — NOT NULL FK на clients.
    $this->clientId = \DB::table('clients')->insertGetId([
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $this->collector = $this->makeCollector();
  }

  private function makeCollector(): User
  {
    // User в этом репо полностью guarded — сырой insert + findOrFail
    // (паттерн DeliveryAssignCouriersTest::makeStaff).
    $userId = \DB::table('users')->insertGetId([
      'name' => 'Collector', 'surname' => 'Тестовый ' . uniqid(),
      'password' => 'test',
      'created_at' => now(), 'updated_at' => now(),
    ]);

    $user = User::findOrFail($userId);
    $user->assignRole(Role::findOrCreate('collector', 'api'));

    \DB::table('shifts')->insert([
      'user_id' => $user->id, 'shop_id' => $this->shopId,
      'created_at' => now(), 'updated_at' => now(),
    ]);

    return $user;
  }

  private function setModule(bool $enabled): void
  {
    \DB::table('admin_settings')->updateOrInsert(
      ['key' => 'project_modules'],
      [
        'value' => json_encode(['collector_payment' => ['label' => 'Списание оплаты сборщиком', 'enabled' => $enabled]]),
        'type' => 'json',
        'updated_at' => now(),
        'created_at' => now(),
      ]
    );
  }

  private function insertOrder(array $attrs = []): int
  {
    return \DB::table('orders')->insertGetId(array_merge([
      'shop_id' => $this->shopId,
      'client_id' => $this->clientId,
      'uuid' => 'chg-' . uniqid(),
      'discount' => 0,
      'delivery_price' => 0,
      'assembly_cost' => 0,
      'bonus_spent' => 0,
      'payment_type_id' => Type::PAYMENT_ONLINE,
      'paid' => 0,
      'type_id' => Type::DELIVERY_COURIER,
      'status_id' => Status::ORDER_PREPARING,
      'amount' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ], $attrs));
  }

  /**
   * Собранный заказ: assembly (user_id = сборщик) + order_item + assembly_item.
   * Возвращает [order_id, assembly_id, product_id].
   *
   * @return array{0:int,1:int,2:int}
   */
  private function insertAssembly(int $orderId, int $itemStatus = Status::PRODUCT_ASSEMBLED, float $itemPrice = 1000.0): array
  {
    $assemblyId = \DB::table('assemblies')->insertGetId([
      'order_id' => $orderId,
      'user_id' => $this->collector->id,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $productId = \DB::table('products')->insertGetId([
      'title' => 'Charge product ' . uniqid(),
      'articul' => 'CHG-' . uniqid(),
      'unit_type_id' => 1,
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $orderItemId = \DB::table('order_items')->insertGetId([
      'order_id' => $orderId,
      'product_id' => $productId,
      'quantity' => 1,
      'price' => $itemPrice,
      'price_during_order' => $itemPrice,
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    \DB::table('assembly_items')->insert([
      'assembly_id' => $assemblyId,
      'order_item_id' => $orderItemId,
      'quantity' => 1,
      'status_id' => $itemStatus,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    return [$orderId, $assemblyId, $productId];
  }

  private function charge(int $assemblyId)
  {
    $this->actingAs($this->collector, 'api');

    return $this->graphQL(self::MUTATION, ['id' => $assemblyId]);
  }

  /** @test */
  public function fails_when_module_disabled(): void
  {
    $this->setModule(false);
    [$orderId, $assemblyId] = $this->insertAssembly($this->insertOrder());

    $response = $this->charge($assemblyId);

    $this->assertNotEmpty($response->json('errors'));
    $this->assertStringContainsString(
      'Списание оплаты сборщиком отключено',
      json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE)
    );
  }

  /** @test */
  public function fails_when_items_left(): void
  {
    $this->setModule(true);
    [$orderId, $assemblyId] = $this->insertAssembly($this->insertOrder(), Status::PRODUCT_WAIT);

    $response = $this->charge($assemblyId);

    $this->assertNotEmpty($response->json('errors'));
    $this->assertStringContainsString(
      'Остались не обработанные товары',
      json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE)
    );
  }

  /** @test */
  public function cash_order_is_noop(): void
  {
    $this->setModule(true);
    Http::fake();
    [$orderId, $assemblyId] = $this->insertAssembly(
      $this->insertOrder(['payment_type_id' => Type::PAYMENT_CASH])
    );

    $response = $this->charge($assemblyId);

    $response->assertJson(['data' => ['AssemblyChargePayment' => ['code' => 200]]]);
    Http::assertNothingSent();
    $this->assertSame(0, (int) \DB::table('orders')->where('id', $orderId)->value('paid'));
  }

  /** @test */
  public function successful_charge_marks_paid(): void
  {
    $this->setModule(true);
    Http::fake(['*' => Http::response(['status' => 'ok', 'payment_status' => 'paid'], 200)]);
    // uuid = storefront-id заказа (числовая строка, заведомо ≠ авто-инкрементному
    // orders.id) — шов «delivery шлёт uuid, а не djami-id» покрывается assertSent ниже.
    [$orderId, $assemblyId] = $this->insertAssembly(
      $this->insertOrder(['uuid' => '987654']), Status::PRODUCT_ASSEMBLED, 1000.0
    );

    $response = $this->charge($assemblyId);

    $response->assertJson(['data' => ['AssemblyChargePayment' => ['code' => 200]]]);

    // storefront биндит {order} по своему id (= наш orders.uuid), не по djami-id.
    Http::assertSent(fn ($request) => str_contains(
      $request->url(), '/api/v1/internal/orders/987654/settle-payment'
    ));

    $order = \DB::table('orders')->where('id', $orderId)->first();
    $this->assertSame(1, (int) $order->paid);
    $this->assertNotNull($order->paid_at);
    $this->assertSame('paid', $order->payment_status);
    // Итог пересчитан assemblyFinish (изначально amount=1 → 1000 по факту сборки)
    $this->assertSame(1000.00, (float) $order->amount);
  }

  /**
   * Чек прихода строится на витрине в момент capture, а событие order.updated
   * с позициями по факту сборки доходит туда позже. Поэтому в запрос списания
   * кладётся тот же payload, что уйдёт в событие: витрина применяет позиции
   * до списания (прод-заказ 1064 Ласточки — чек по заказанному весу).
   *
   * @test
   */
  public function settle_request_carries_order_event_with_assembled_items(): void
  {
    $this->setModule(true);
    Http::fake(['*' => Http::response(['status' => 'ok', 'payment_status' => 'paid'], 200)]);
    [, $assemblyId] = $this->insertAssembly(
      $this->insertOrder(['uuid' => '987655']), Status::PRODUCT_ASSEMBLED, 1000.0
    );

    $this->charge($assemblyId)
      ->assertJson(['data' => ['AssemblyChargePayment' => ['code' => 200]]]);

    Http::assertSent(function ($request) {
      $event = $request['order_event'] ?? null;

      return is_array($event)
        && $event['id'] === '987655'
        && (float) $event['grand_total'] === 1000.0
        && count($event['items'] ?? []) === 1;
    });
  }

  /** @test */
  public function declined_charge_returns_402_and_keeps_order_preparing(): void
  {
    $this->setModule(true);
    Http::fake(['*' => Http::response([
      'status' => 'failed',
      'message' => 'Недостаточно средств на карте',
      'surcharge_amount' => 1000,
    ], 402)]);
    [$orderId, $assemblyId] = $this->insertAssembly($this->insertOrder(['uuid' => '987654']));

    $response = $this->charge($assemblyId);

    $response->assertJson(['data' => ['AssemblyChargePayment' => ['code' => 402]]]);
    // Шов: списание уходит на storefront-id (uuid), не на djami-id заказа.
    Http::assertSent(fn ($request) => str_contains(
      $request->url(), '/api/v1/internal/orders/987654/settle-payment'
    ));
    $message = $response->json('data.AssemblyChargePayment.message');
    $this->assertStringContainsString('Недостаточно средств на карте', $message);
    $this->assertStringContainsString('скорректируйте состав', $message);

    $order = \DB::table('orders')->where('id', $orderId)->first();
    $this->assertSame(Status::ORDER_PREPARING, (int) $order->status_id);
    $this->assertSame(0, (int) $order->paid);
    $this->assertSame('awaiting_surcharge', $order->payment_status);
  }

  /** @test */
  public function storefront_unavailable_returns_500(): void
  {
    $this->setModule(true);
    Http::fake(['*' => Http::response(null, 500)]);
    [$orderId, $assemblyId] = $this->insertAssembly($this->insertOrder());

    $response = $this->charge($assemblyId);

    $response->assertJson(['data' => ['AssemblyChargePayment' => ['code' => 500]]]);

    $order = \DB::table('orders')->where('id', $orderId)->first();
    $this->assertNull($order->payment_status);
    $this->assertSame(0, (int) $order->paid);
  }

  /** @test */
  public function retryable_capture_failure_returns_500_without_awaiting_status(): void
  {
    // 503 «доплата прошла, capture упал»: транзиентный сбой — payment_status
    // НЕ ставим (awaiting_surcharge только на 402), повторный вызов допустим.
    $this->setModule(true);
    Http::fake(['*' => Http::response([
      'status' => 'retry',
      'message' => 'Техническая ошибка списания, попробуйте ещё раз',
    ], 503)]);
    [$orderId, $assemblyId] = $this->insertAssembly($this->insertOrder());

    $response = $this->charge($assemblyId);

    $response->assertJson(['data' => ['AssemblyChargePayment' => ['code' => 500]]]);

    $order = \DB::table('orders')->where('id', $orderId)->first();
    $this->assertNull($order->payment_status);
    $this->assertSame(0, (int) $order->paid);
  }

  /** @test */
  public function successful_charge_does_not_touch_stock_when_shop_tracks_remains(): void
  {
    // Fix-1: списание остатка в collectOrderItems относительное и не идемпотентное.
    // «Списать оплату» зовёт assemblyFinish($assembly, false) → остаток НЕ трогается;
    // он спишется ровно один раз при завершении сборки (AssemblyFinish::finishOrder).
    $this->setModule(true);
    Http::fake(['*' => Http::response(['status' => 'ok', 'payment_status' => 'paid'], 200)]);

    // Магазин, который учитывает остатки (ignore_stock = 0)
    $stockShopId = \DB::table('shops')->insertGetId([
      'title' => 'Stock shop',
      'id_1c' => random_int(900000, 999999),
      'default' => 0,
      'slot_max_orders' => 10,
      'slot_time' => 60,
      'is_popular' => 0,
      'is_pickup_enabled' => 0,
      'pickup_auto_cancel_enabled' => 0,
      'ignore_stock' => 0,
      'is_bonus_enabled' => 0,
      'bonus_spend_percent' => 0,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    [$orderId, $assemblyId, $productId] = $this->insertAssembly(
      $this->insertOrder(['shop_id' => $stockShopId])
    );

    $remainId = \DB::table('remains')->insertGetId([
      'product_id' => $productId,
      'shop_id' => $stockShopId,
      'quantity' => 100,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $response = $this->charge($assemblyId);

    $response->assertJson(['data' => ['AssemblyChargePayment' => ['code' => 200]]]);
    // Остаток не тронут списанием — charge не вычитал 1 из 100
    $this->assertSame(100, (int) \DB::table('remains')->where('id', $remainId)->value('quantity'));
    // При этом оплата отмечена
    $this->assertSame(1, (int) \DB::table('orders')->where('id', $orderId)->value('paid'));
  }
}
