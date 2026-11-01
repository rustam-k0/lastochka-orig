<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use App\Services\FcmNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Гейт «Назначить курьера» по оплате (модуль collector_payment).
 *
 * При включённом модуле неоплаченный онлайн-заказ (payment_type_id=1, paid=0)
 * нельзя отдавать курьеру: ни через GraphQL DeliveryAssignCouriers (сборщик
 * из мобилки), ни через REST OrderController::delivery (админка через
 * purchase_back). Belt-and-suspenders поверх Task 10 (гейт AssemblyFinish),
 * т.к. legacy-заказы и админский REST-путь могут добраться до ASSEMBLED
 * с paid=0.
 */
class CourierAssignPaymentGateTest extends TestCase
{
  use DatabaseTransactions, MakesGraphQLRequests;

  private int $shopId;
  private int $clientId;
  private User $collector;
  private int $courier1Id;
  private int $courier2Id;

  private const MUTATION = '
    mutation DeliveryAssignCouriers($order_id: Int!, $courier_ids: [Int!]!) {
      DeliveryAssignCouriers(order_id: $order_id, courier_ids: $courier_ids) { code message }
    }
  ';

  protected function setUp(): void
  {
    parent::setUp();

    // FCM не должен ходить наружу (успешное назначение шлёт пуш курьеру)
    $this->mock(FcmNotificationService::class)
      ->shouldReceive('sendToUser')->andReturnNull();

    // default=1 — fallback-фильтр ShopService(null) в scopeCurrentShop
    // (валидатор ходит через Order::currentShop()->find), паттерн из
    // DeliveryAssignCouriersTest.
    $this->shopId = \DB::table('shops')->insertGetId([
      'title' => 'Payment gate assign shop', 'id_1c' => random_int(900000, 999999), 'default' => 1,
      'slot_max_orders' => 10, 'slot_time' => 60, 'is_popular' => 0,
      'is_pickup_enabled' => 0, 'pickup_auto_cancel_enabled' => 0,
      'ignore_stock' => 0, 'is_bonus_enabled' => 0,
      'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->clientId = \DB::table('clients')->insertGetId([
      'password' => 'test',
      'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->collector = $this->makeStaff('collector');
    $this->courier1Id = $this->makeStaff('courier')->id;
    $this->courier2Id = $this->makeStaff('courier')->id;
  }

  private function makeStaff(string $roleName): User
  {
    // User в этом репо полностью guarded — mass-assignment через
    // User::create бросает MassAssignmentException. Сырой insert + findOrFail
    // (паттерн DeliveryAssignCouriersTest/OrderAssignPermissionTest).
    $userId = \DB::table('users')->insertGetId([
      'name' => ucfirst($roleName), 'surname' => 'Тестовый ' . uniqid(),
      'password' => 'test',
      'created_at' => now(), 'updated_at' => now(),
    ]);

    $user = User::findOrFail($userId);
    $user->assignRole(Role::findOrCreate($roleName, 'api'));

    \DB::table('shifts')->insert([
      'user_id' => $user->id, 'shop_id' => $this->shopId,
      'created_at' => now(), 'updated_at' => now(),
    ]);

    return $user;
  }

  private function insertOrder(array $attrs = []): int
  {
    return \DB::table('orders')->insertGetId(array_merge([
      'shop_id' => $this->shopId, 'client_id' => $this->clientId, 'uuid' => 'cpg-' . uniqid(),
      'discount' => 0, 'payment_type_id' => Type::PAYMENT_ONLINE, 'paid' => 0, 'type_id' => 2,
      'status_id' => Status::ORDER_ASSEMBLED, 'amount' => 100, 'is_heavy' => 0,
      'slot_start_date' => now(), 'slot_end_date' => now(),
      'created_at' => now(), 'updated_at' => now(),
    ], $attrs));
  }

  private function setModules(bool $collectorPayment, bool $courierAssignment = true, bool $heavyOrders = false): void
  {
    \DB::table('admin_settings')->updateOrInsert(
      ['key' => 'project_modules'],
      ['value' => json_encode([
        'collector_payment' => ['label' => 'Списание оплаты сборщиком', 'enabled' => $collectorPayment],
        'courier_assignment' => ['label' => 'Назначение курьера сборщиком', 'enabled' => $courierAssignment],
        'heavy_orders' => ['label' => 'Тяжёлые заказы', 'enabled' => $heavyOrders],
      ]), 'type' => 'json', 'updated_at' => now(), 'created_at' => now()]
    );
  }

  private function assign(int $orderId, array $courierIds)
  {
    $this->actingAs($this->collector, 'api');

    return $this->graphQL(self::MUTATION, ['order_id' => $orderId, 'courier_ids' => $courierIds]);
  }

  // ---------------------------------------------------------------------
  // GraphQL: DeliveryAssignCouriers
  // ---------------------------------------------------------------------

  /** @test */
  public function graphql_blocks_assignment_when_module_on_online_unpaid()
  {
    $this->setModules(true);
    $orderId = $this->insertOrder(['payment_type_id' => Type::PAYMENT_ONLINE, 'paid' => 0]);

    $response = $this->assign($orderId, [$this->courier1Id]);

    $this->assertSame(
      'Заказ не оплачен: спишите оплату перед назначением курьера',
      $response->json('errors.0.extensions.validation.order_id.0')
    );
    $this->assertSame(0, \DB::table('delivery_orders')->where('order_id', $orderId)->count());
  }

  /** @test */
  public function graphql_allows_assignment_when_module_on_online_paid()
  {
    $this->setModules(true);
    $orderId = $this->insertOrder(['payment_type_id' => Type::PAYMENT_ONLINE, 'paid' => 1]);

    $response = $this->assign($orderId, [$this->courier1Id]);

    $response->assertJson(['data' => ['DeliveryAssignCouriers' => ['code' => 200]]]);
    $this->assertSame(1, \DB::table('delivery_orders')->where('order_id', $orderId)->count());
  }

  /** @test */
  public function graphql_allows_assignment_when_module_on_cash_unpaid()
  {
    $this->setModules(true);
    $orderId = $this->insertOrder(['payment_type_id' => Type::PAYMENT_CASH, 'paid' => 0]);

    $response = $this->assign($orderId, [$this->courier1Id]);

    $response->assertJson(['data' => ['DeliveryAssignCouriers' => ['code' => 200]]]);
    $this->assertSame(1, \DB::table('delivery_orders')->where('order_id', $orderId)->count());
  }

  /** @test */
  public function graphql_allows_assignment_when_module_off_online_unpaid()
  {
    $this->setModules(false);
    $orderId = $this->insertOrder(['payment_type_id' => Type::PAYMENT_ONLINE, 'paid' => 0]);

    $response = $this->assign($orderId, [$this->courier1Id]);

    $response->assertJson(['data' => ['DeliveryAssignCouriers' => ['code' => 200]]]);
    $this->assertSame(1, \DB::table('delivery_orders')->where('order_id', $orderId)->count());
  }

  /**
   * Взаимодействие с $isHeavy-веткой: гейт по оплате стоит ДО проверок
   * $isHeavy и должен отрабатывать одинаково что для обычного, что для
   * тяжёлого заказа — донабор курьера к тяжёлому заказу в пути (IN_DELIVERY)
   * тоже блокируется, если оплата не списана.
   *
   * @test
   */
  public function graphql_blocks_heavy_order_donabor_in_delivery_when_unpaid()
  {
    $this->setModules(true, true, false);
    $orderId = $this->insertOrder([
      'is_heavy' => 1,
      'status_id' => Status::ORDER_IN_DELIVERY,
      'payment_type_id' => Type::PAYMENT_ONLINE,
      'paid' => 0,
    ]);

    $response = $this->assign($orderId, [$this->courier1Id]);

    $this->assertSame(
      'Заказ не оплачен: спишите оплату перед назначением курьера',
      $response->json('errors.0.extensions.validation.order_id.0')
    );
    $this->assertSame(0, \DB::table('delivery_orders')->where('order_id', $orderId)->count());
  }

  /**
   * Тот же донабор, но заказ оплачен — должен пройти как раньше
   * (регрессия к courier_assignment_module_allows_adding_courier_to_heavy_order_in_delivery).
   *
   * @test
   */
  public function graphql_allows_heavy_order_donabor_in_delivery_when_paid()
  {
    $this->setModules(true, true, false);
    $orderId = $this->insertOrder([
      'is_heavy' => 1,
      'status_id' => Status::ORDER_IN_DELIVERY,
      'payment_type_id' => Type::PAYMENT_ONLINE,
      'paid' => 1,
    ]);

    $response = $this->assign($orderId, [$this->courier1Id]);

    $response->assertJson(['data' => ['DeliveryAssignCouriers' => ['code' => 200]]]);
    $this->assertSame(1, \DB::table('delivery_orders')->where('order_id', $orderId)->count());
  }

  // ---------------------------------------------------------------------
  // REST: OrderController::delivery
  // ---------------------------------------------------------------------

  /** @test */
  public function rest_blocks_delivery_when_module_on_online_unpaid()
  {
    config(['services.system.token' => 'test-token']);
    $this->setModules(true);
    $orderId = $this->insertOrder(['payment_type_id' => Type::PAYMENT_ONLINE, 'paid' => 0]);

    $response = $this->post('/backend-delivery/order/delivery', [
      'token' => 'test-token',
      'order_id' => $orderId,
      'courier_id' => $this->courier1Id,
    ]);

    $response->assertStatus(422);
    $response->assertJson(['message' => 'Заказ не оплачен: назначение курьера недоступно']);
    $this->assertSame(0, \DB::table('delivery_orders')->where('order_id', $orderId)->count());
  }

  /** @test */
  public function rest_allows_delivery_when_module_on_online_paid()
  {
    config(['services.system.token' => 'test-token']);
    $this->setModules(true);
    $orderId = $this->insertOrder(['payment_type_id' => Type::PAYMENT_ONLINE, 'paid' => 1]);

    $response = $this->post('/backend-delivery/order/delivery', [
      'token' => 'test-token',
      'order_id' => $orderId,
      'courier_id' => $this->courier1Id,
    ]);

    $response->assertStatus(200);
    $this->assertSame(1, \DB::table('delivery_orders')->where('order_id', $orderId)->count());
  }

  /** @test */
  public function rest_allows_delivery_when_module_on_cash_unpaid()
  {
    config(['services.system.token' => 'test-token']);
    $this->setModules(true);
    $orderId = $this->insertOrder(['payment_type_id' => Type::PAYMENT_CASH, 'paid' => 0]);

    $response = $this->post('/backend-delivery/order/delivery', [
      'token' => 'test-token',
      'order_id' => $orderId,
      'courier_id' => $this->courier1Id,
    ]);

    $response->assertStatus(200);
    $this->assertSame(1, \DB::table('delivery_orders')->where('order_id', $orderId)->count());
  }

  /** @test */
  public function rest_allows_delivery_when_module_off_online_unpaid()
  {
    config(['services.system.token' => 'test-token']);
    $this->setModules(false);
    $orderId = $this->insertOrder(['payment_type_id' => Type::PAYMENT_ONLINE, 'paid' => 0]);

    $response = $this->post('/backend-delivery/order/delivery', [
      'token' => 'test-token',
      'order_id' => $orderId,
      'courier_id' => $this->courier1Id,
    ]);

    $response->assertStatus(200);
    $this->assertSame(1, \DB::table('delivery_orders')->where('order_id', $orderId)->count());
  }
}
