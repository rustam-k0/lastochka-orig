<?php

namespace Tests\Feature;

use App\Models\Assembly;
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
 * Списание оплаты в контексте завершения сборки (модуль collector_payment).
 *
 * Отдельной кнопки «Списать оплату» у сборщика нет: AssemblyFinish (GraphQL)
 * и REST OrderController::assemblyFinish сами выполняют списание для
 * неоплаченного онлайн-заказа (доплата первой, затем capture — на стороне
 * storefront). Провал списания (402/500) возвращается ответом мутации,
 * заказ остаётся на сборке, сток не списывается.
 */
class AssemblyFinishPaymentGateTest extends TestCase
{
  use DatabaseTransactions, MakesGraphQLRequests;

  private int $shopId;
  private int $clientId;
  private User $collector;

  private const MUTATION = '
    mutation AssemblyFinish($id: Int, $packages: [PackageInput]) {
      AssemblyFinish(id: $id, packages: $packages) { code message }
    }
  ';

  protected function setUp(): void
  {
    parent::setUp();

    // FCM не должен ходить наружу (finishOrder шлёт пуш курьерам при успехе).
    $this->mock(FcmNotificationService::class)
      ->shouldReceive('sendToUser')->andReturnNull()
      ->shouldReceive('sendToUsers')->andReturnNull();

    $this->shopId = \DB::table('shops')->insertGetId([
      'title' => 'Payment gate shop',
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

    $this->clientId = \DB::table('clients')->insertGetId([
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    // finishOrder() при успехе шлёт пуш всем курьерам магазина (notifyCouriersOrderReady) —
    // роль должна существовать, иначе Spatie::role() падает "no role named `courier`"
    // даже когда курьеров с этой ролью нет.
    Role::findOrCreate('courier', 'api');

    $this->collector = $this->makeCollector();
  }

  private function makeCollector(): User
  {
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
      'uuid' => 'pgt-' . uniqid(),
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
   * Собранный заказ: assembly (user_id = сборщик) + order_item + assembly_item,
   * все позиции в статусе PRODUCT_ASSEMBLED (валидатор AssemblyFinish пропускает).
   *
   * @return array{0:int,1:int} [order_id, assembly_id]
   */
  private function insertAssembly(int $orderId): array
  {
    $assemblyId = \DB::table('assemblies')->insertGetId([
      'order_id' => $orderId,
      'user_id' => $this->collector->id,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $productId = \DB::table('products')->insertGetId([
      'title' => 'Payment gate product ' . uniqid(),
      'articul' => 'PGT-' . uniqid(),
      'unit_type_id' => 1,
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $orderItemId = \DB::table('order_items')->insertGetId([
      'order_id' => $orderId,
      'product_id' => $productId,
      'quantity' => 1,
      'price' => 1000,
      'price_during_order' => 1000,
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    \DB::table('assembly_items')->insert([
      'assembly_id' => $assemblyId,
      'order_item_id' => $orderItemId,
      'quantity' => 1,
      'status_id' => Status::PRODUCT_ASSEMBLED,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    return [$orderId, $assemblyId];
  }

  private function finishViaGraphQL(int $assemblyId)
  {
    $this->actingAs($this->collector, 'api');

    return $this->graphQL(self::MUTATION, [
      'id' => $assemblyId,
      'packages' => [['quantity' => 1, 'type_id' => Type::PACKAGE_CABINET]],
    ]);
  }

  /** @test */
  public function graphql_finish_charges_and_completes_when_unpaid(): void
  {
    $this->setModule(true);
    Http::fake(['*' => Http::response(['status' => 'ok', 'payment_status' => 'paid'], 200)]);

    [$orderId, $assemblyId] = $this->insertAssembly($this->insertOrder(['uuid' => '987001']));

    $response = $this->finishViaGraphQL($assemblyId);

    $response->assertJson(['data' => ['AssemblyFinish' => ['code' => 200]]]);

    // Списание ушло в storefront по uuid (= storefront-id), не по djami-id
    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/internal/orders/987001/settle-payment'));

    $order = \DB::table('orders')->where('id', $orderId)->first();
    $this->assertSame(1, (int) $order->paid);
    $this->assertSame('paid', $order->payment_status);
    $this->assertSame(Status::ORDER_ASSEMBLED, (int) $order->status_id);

    $assembly = \DB::table('assemblies')->where('id', $assemblyId)->first();
    $this->assertNotNull($assembly->finished_at);
  }

  /** @test */
  public function graphql_finish_returns_402_when_surcharge_declined(): void
  {
    $this->setModule(true);
    Http::fake(['*' => Http::response([
      'status' => 'failed',
      'message' => 'Недостаточно средств на карте',
      'surcharge_amount' => 100,
    ], 402)]);

    [$orderId, $assemblyId] = $this->insertAssembly($this->insertOrder());

    $response = $this->finishViaGraphQL($assemblyId);

    $response->assertJson(['data' => ['AssemblyFinish' => ['code' => 402]]]);
    $message = $response->json('data.AssemblyFinish.message');
    $this->assertStringContainsString('Не удалось списать оплату', $message);
    $this->assertStringContainsString('Недостаточно средств на карте', $message);
    $this->assertStringContainsString('скорректируйте состав заказа', $message);

    $order = \DB::table('orders')->where('id', $orderId)->first();
    $this->assertSame(0, (int) $order->paid);
    $this->assertSame('awaiting_surcharge', $order->payment_status);
    $this->assertSame(Status::ORDER_PREPARING, (int) $order->status_id);

    $assembly = \DB::table('assemblies')->where('id', $assemblyId)->first();
    $this->assertNull($assembly->finished_at);
  }

  /** @test */
  public function graphql_finish_returns_500_on_transient_failure(): void
  {
    $this->setModule(true);
    Http::fake(['*' => Http::response(['status' => 'retry'], 503)]);

    [$orderId, $assemblyId] = $this->insertAssembly($this->insertOrder());

    $response = $this->finishViaGraphQL($assemblyId);

    $response->assertJson(['data' => ['AssemblyFinish' => ['code' => 500]]]);

    $order = \DB::table('orders')->where('id', $orderId)->first();
    $this->assertSame(0, (int) $order->paid);
    $this->assertNull($order->payment_status);
    $this->assertSame(Status::ORDER_PREPARING, (int) $order->status_id);
  }

  /** @test */
  public function graphql_allows_finish_when_module_on_and_paid(): void
  {
    $this->setModule(true);
    Http::fake();

    [$orderId, $assemblyId] = $this->insertAssembly($this->insertOrder(['paid' => 1]));

    $response = $this->finishViaGraphQL($assemblyId);

    $response->assertJson(['data' => ['AssemblyFinish' => ['code' => 200]]]);
    Http::assertNothingSent();

    $order = \DB::table('orders')->where('id', $orderId)->first();
    $this->assertSame(Status::ORDER_ASSEMBLED, (int) $order->status_id);

    $assembly = \DB::table('assemblies')->where('id', $assemblyId)->first();
    $this->assertNotNull($assembly->finished_at);
  }

  /** @test */
  public function graphql_allows_finish_when_module_off(): void
  {
    $this->setModule(false);
    Http::fake();

    [$orderId, $assemblyId] = $this->insertAssembly($this->insertOrder());

    $response = $this->finishViaGraphQL($assemblyId);

    $response->assertJson(['data' => ['AssemblyFinish' => ['code' => 200]]]);
    Http::assertNothingSent();

    $order = \DB::table('orders')->where('id', $orderId)->first();
    $this->assertSame(Status::ORDER_ASSEMBLED, (int) $order->status_id);
  }

  /** @test */
  public function rest_finish_charges_and_completes(): void
  {
    config(['services.system.token' => 'test-token']);
    $this->setModule(true);
    Http::fake(['*' => Http::response(['status' => 'ok', 'payment_status' => 'paid'], 200)]);

    [$orderId, $assemblyId] = $this->insertAssembly($this->insertOrder(['uuid' => '987002']));

    $response = $this->post('/backend-delivery/order/assembly-finish', [
      'token' => 'test-token',
      'order_id' => $orderId,
    ]);

    $response->assertStatus(200);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/internal/orders/987002/settle-payment'));

    $order = \DB::table('orders')->where('id', $orderId)->first();
    $this->assertSame(1, (int) $order->paid);
    $this->assertSame(Status::ORDER_ASSEMBLED, (int) $order->status_id);
  }

  /** @test */
  public function rest_finish_returns_402_when_surcharge_declined(): void
  {
    config(['services.system.token' => 'test-token']);
    $this->setModule(true);
    Http::fake(['*' => Http::response(['status' => 'failed', 'message' => 'Недостаточно средств на карте', 'surcharge_amount' => 100], 402)]);

    [$orderId, $assemblyId] = $this->insertAssembly($this->insertOrder());

    $response = $this->post('/backend-delivery/order/assembly-finish', [
      'token' => 'test-token',
      'order_id' => $orderId,
    ]);

    $response->assertStatus(402);

    $order = \DB::table('orders')->where('id', $orderId)->first();
    $this->assertSame(Status::ORDER_PREPARING, (int) $order->status_id);

    $assembly = \DB::table('assemblies')->where('id', $assemblyId)->first();
    $this->assertNull($assembly->finished_at);
  }

  /** @test */
  public function graphql_402_does_not_write_off_stock(): void
  {
    // Провал списания не должен иметь складских побочных эффектов:
    // chargeForAssembly пересчитывает с adjustStock=false, сток списывается
    // один раз — только при успешном завершении (E2E на dev ловил двойное
    // списание, когда пересчёт со стоком шёл до проверки оплаты).
    $this->setModule(true);
    Http::fake(['*' => Http::response(['status' => 'failed', 'message' => 'Недостаточно средств на карте', 'surcharge_amount' => 100], 402)]);

    $stockShopId = \DB::table('shops')->insertGetId([
      'title' => 'Stock shop gate',
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

    [$orderId, $assemblyId] = $this->insertAssembly($this->insertOrder(['shop_id' => $stockShopId]));

    $productId = (int) \DB::table('order_items')->where('order_id', $orderId)->value('product_id');
    $remainId = \DB::table('remains')->insertGetId([
      'product_id' => $productId,
      'shop_id' => $stockShopId,
      'quantity' => 100,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $response = $this->finishViaGraphQL($assemblyId);

    $response->assertJson(['data' => ['AssemblyFinish' => ['code' => 402]]]);
    $this->assertSame(100, (int) \DB::table('remains')->where('id', $remainId)->value('quantity'));
    $this->assertSame(Status::ORDER_PREPARING, (int) \DB::table('orders')->where('id', $orderId)->value('status_id'));
  }
}
