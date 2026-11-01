<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Assembly\AssemblyFinish;
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
 * Списание оплаты (модуль collector_payment) для заказов, расщеплённых на
 * подзаказы по зонам сборки (splitOrderByZones в admin_back).
 *
 * Деньги живут на РОДИТЕЛЕ: дети создаются с delivery_price = 0, bonus_spent = 0,
 * assembly_cost = 0, discount = 0, а холд в storefront сделан на полную сумму
 * родителя. Поэтому списание обязано уходить на parent->amount и ровно один раз —
 * когда собран (или отменён) последний подзаказ.
 *
 * Регрессия LASTOCHKI: заказ 156 на проде — холд 780.70 (товары 530.70 + доставка
 * 250), списали amount подзаказа 530.70, ЮKassa освободила 250 → недосбор доставки.
 */
class AssemblyChargeSplitOrderTest extends TestCase
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

    $this->mock(FcmNotificationService::class)
      ->shouldReceive('sendToUser')->andReturnNull()
      ->shouldReceive('sendToUsers')->andReturnNull();

    $this->shopId = \DB::table('shops')->insertGetId([
      'title' => 'Split charge shop',
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

    $this->collector = $this->makeCollector();

    $this->setModule(true);
  }

  private function makeCollector(): User
  {
    $userId = \DB::table('users')->insertGetId([
      'name' => 'Collector', 'surname' => 'Сплит ' . uniqid(),
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
      'uuid' => 'split-' . uniqid(),
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
   * Подзаказ с собранной позицией. Возвращает [order_id, assembly_id].
   *
   * @return array{0:int,1:int}
   */
  private function insertSubOrderWithAssembly(
    int $parentId,
    int $subNumber,
    float $itemPrice,
    int $itemStatus = Status::PRODUCT_ASSEMBLED
  ): array {
    $childId = $this->insertOrder([
      'parent_order_id' => $parentId,
      'sub_number' => $subNumber,
      'uuid' => \DB::table('orders')->where('id', $parentId)->value('uuid') . '-' . $subNumber,
      'status_id' => Status::ORDER_PREPARING,
      'amount' => $itemPrice,
      'origin_amount' => $itemPrice,
    ]);

    $assemblyId = \DB::table('assemblies')->insertGetId([
      'order_id' => $childId,
      'user_id' => $this->collector->id,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $productId = \DB::table('products')->insertGetId([
      'title' => 'Split product ' . uniqid(),
      'articul' => 'SPL-' . uniqid(),
      'unit_type_id' => 1,
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $orderItemId = \DB::table('order_items')->insertGetId([
      'order_id' => $childId,
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

    return [$childId, $assemblyId];
  }

  private function charge(int $assemblyId)
  {
    $this->actingAs($this->collector, 'api');

    return $this->graphQL(self::MUTATION, ['id' => $assemblyId]);
  }

  private function orderRow(int $orderId)
  {
    return \DB::table('orders')->where('id', $orderId)->first();
  }

  /** @test */
  public function single_sub_order_settles_parent_amount_including_delivery(): void
  {
    Http::fake(['*' => Http::response(['status' => 'ok', 'payment_status' => 'paid'], 200)]);

    $parentId = $this->insertOrder(['uuid' => '156', 'delivery_price' => 250, 'amount' => 780.70]);
    [$childId, $assemblyId] = $this->insertSubOrderWithAssembly($parentId, 1, 530.70);

    $response = $this->charge($assemblyId);

    $response->assertJson(['data' => ['AssemblyChargePayment' => ['code' => 200]]]);

    // Списание уходит на storefront-id РОДИТЕЛЯ и на его сумму (товары + доставка).
    Http::assertSent(function ($request) {
      return str_contains($request->url(), '/api/v1/internal/orders/156/settle-payment')
        && abs((float) $request['amount'] - 780.70) < 0.01;
    });

    $parent = $this->orderRow($parentId);
    $this->assertSame(1, (int) $parent->paid);
    $this->assertSame('paid', $parent->payment_status);
    $this->assertNotNull($parent->paid_at);
    $this->assertSame(780.70, (float) $parent->amount);

    // Подзаказ тоже помечен оплаченным: состав заблокирован для правок
    // (Order::isCompositionLockedForAssembly смотрит на сам заказ).
    $this->assertSame(1, (int) $this->orderRow($childId)->paid);
  }

  /** @test */
  public function charge_waits_until_all_sub_orders_are_finished(): void
  {
    Http::fake(['*' => Http::response(['status' => 'ok', 'payment_status' => 'paid'], 200)]);

    // uuid родителя — storefront-id заказа (числовая строка).
    $parentId = $this->insertOrder(['uuid' => '774411', 'delivery_price' => 100, 'amount' => 1100]);
    [$firstChildId, $firstAssemblyId] = $this->insertSubOrderWithAssembly($parentId, 1, 400.0);
    [$secondChildId, $secondAssemblyId] = $this->insertSubOrderWithAssembly($parentId, 2, 600.0);

    // Первая зона собрана — вторая ещё в работе: списывать нельзя,
    // иначе capture закроет холд на частичную сумму.
    $firstResponse = $this->charge($firstAssemblyId);
    $firstResponse->assertJson(['data' => ['AssemblyChargePayment' => ['code' => 200]]]);
    Http::assertNothingSent();
    $this->assertSame(0, (int) $this->orderRow($parentId)->paid);

    // Первый подзаказ доводим до ASSEMBLED (как это делает finishOrder).
    AssemblyFinish::finishOrder([], Assembly::findOrFail($firstAssemblyId));

    $secondResponse = $this->charge($secondAssemblyId);
    $secondResponse->assertJson(['data' => ['AssemblyChargePayment' => ['code' => 200]]]);

    $parentUuid = $this->orderRow($parentId)->uuid;

    Http::assertSentCount(1);
    Http::assertSent(function ($request) use ($parentUuid) {
      return str_contains($request->url(), "/api/v1/internal/orders/{$parentUuid}/settle-payment")
        && abs((float) $request['amount'] - 1100.0) < 0.01;
    });

    $this->assertSame(1, (int) $this->orderRow($parentId)->paid);
    $this->assertSame(1, (int) $this->orderRow($firstChildId)->paid);
    $this->assertSame(1, (int) $this->orderRow($secondChildId)->paid);
  }

  /** @test */
  public function declined_charge_marks_parent_awaiting_surcharge(): void
  {
    Http::fake(['*' => Http::response([
      'status' => 'failed',
      'message' => 'Недостаточно средств на карте',
      'surcharge_amount' => 250,
    ], 402)]);

    $parentId = $this->insertOrder(['delivery_price' => 250, 'amount' => 780.70]);
    [$childId, $assemblyId] = $this->insertSubOrderWithAssembly($parentId, 1, 530.70);

    $response = $this->charge($assemblyId);

    $response->assertJson(['data' => ['AssemblyChargePayment' => ['code' => 402]]]);

    $parent = $this->orderRow($parentId);
    $this->assertSame(0, (int) $parent->paid);
    $this->assertSame('awaiting_surcharge', $parent->payment_status);
    $this->assertSame(Status::ORDER_PREPARING, (int) $this->orderRow($childId)->status_id);
  }

  /** @test */
  public function parent_is_settled_when_last_sibling_is_cancelled(): void
  {
    Http::fake(['*' => Http::response(['status' => 'ok', 'payment_status' => 'paid'], 200)]);

    $parentId = $this->insertOrder(['uuid' => '774422', 'delivery_price' => 100, 'amount' => 1100]);
    [$assembledChildId, $assembledAssemblyId] = $this->insertSubOrderWithAssembly($parentId, 1, 400.0);
    [$cancelledChildId, $cancelledAssemblyId] = $this->insertSubOrderWithAssembly($parentId, 2, 600.0);

    // Зона 1 собрана — ждём вторую, списания нет.
    $this->charge($assembledAssemblyId);
    Http::assertNothingSent();
    AssemblyFinish::finishOrder([], Assembly::findOrFail($assembledAssemblyId));

    // Зона 2 отменена сборщиком целиком: списание за собранную часть
    // обязано пройти, иначе заказ уедет курьеру неоплаченным.
    AssemblyFinish::finishAssemblyAndCancelOrder(Assembly::findOrFail($cancelledAssemblyId));

    $parentUuid = $this->orderRow($parentId)->uuid;

    Http::assertSentCount(1);
    Http::assertSent(function ($request) use ($parentUuid) {
      return str_contains($request->url(), "/api/v1/internal/orders/{$parentUuid}/settle-payment")
        && abs((float) $request['amount'] - 500.0) < 0.01;
    });

    $parent = $this->orderRow($parentId);
    $this->assertSame(1, (int) $parent->paid);
    $this->assertSame(Status::ORDER_ASSEMBLED, (int) $parent->status_id);
  }

  /** @test */
  public function cancelled_parent_is_not_charged(): void
  {
    // Клиент отменил заказ через storefront, каскад отмены ещё не дошёл до
    // подзаказа: списывать деньги по отменённому родителю нельзя.
    Http::fake(['*' => Http::response(['status' => 'ok', 'payment_status' => 'paid'], 200)]);

    $parentId = $this->insertOrder([
      'uuid' => '774433',
      'delivery_price' => 250,
      'amount' => 780.70,
      'status_id' => Status::ORDER_CANCELLED,
    ]);
    [$childId, $assemblyId] = $this->insertSubOrderWithAssembly($parentId, 1, 530.70);

    $response = $this->charge($assemblyId);

    $response->assertJson(['data' => ['AssemblyChargePayment' => ['code' => 200]]]);
    Http::assertNothingSent();
    $this->assertSame(0, (int) $this->orderRow($parentId)->paid);
  }

  /** @test */
  public function already_paid_parent_is_not_charged_twice(): void
  {
    Http::fake(['*' => Http::response(['status' => 'ok', 'payment_status' => 'paid'], 200)]);

    $parentId = $this->insertOrder([
      'delivery_price' => 250,
      'amount' => 780.70,
      'paid' => 1,
      'payment_status' => 'paid',
      'paid_at' => now(),
    ]);
    [$childId, $assemblyId] = $this->insertSubOrderWithAssembly($parentId, 1, 530.70);

    $response = $this->charge($assemblyId);

    $response->assertJson(['data' => ['AssemblyChargePayment' => ['code' => 200]]]);
    Http::assertNothingSent();
  }
}
