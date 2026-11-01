<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use App\Services\FcmNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Блокировка правок состава сборки после успешного «Списать оплату»
 * (модуль collector_payment, онлайн-оплата, orders.paid=1).
 *
 * После списания оплаты сумма списания зафиксирована — сборщик больше не
 * должен менять состав, иначе сумма перестанет соответствовать позициям.
 * Гейт живёт в валидаторах Product*-мутаций через
 * Order::isCompositionLockedForAssembly() (Task 8).
 *
 * При paid=0 (в т.ч. после 402 «доплата не прошла») правки штатно разрешены —
 * это сценарий корректировки состава. Модуль OFF → старое поведение форков.
 */
class AssemblyCompositionLockTest extends TestCase
{
  use DatabaseTransactions, MakesGraphQLRequests;

  private const LOCK_MESSAGE = 'Оплата по заказу уже списана, изменение состава недоступно';

  private const M_PRODUCT_CANCEL = '
    mutation ProductCancel($id: Int!) {
      ProductCancel(id: $id) { code message }
    }
  ';

  private const M_PRODUCT_CONFIRM = '
    mutation ProductConfirm($assembly_item_id: Int!, $quantity: Int) {
      ProductConfirm(assembly_item_id: $assembly_item_id, quantity: $quantity) { code message }
    }
  ';

  private const M_PRODUCT_ADD = '
    mutation ProductAddToOrder($assembly_id: Int!, $product_id: Int!, $quantity: Int!) {
      ProductAddToOrder(assembly_id: $assembly_id, product_id: $product_id, quantity: $quantity) { code message }
    }
  ';

  private int $shopId;
  private int $clientId;
  private User $collector;

  protected function setUp(): void
  {
    parent::setUp();

    // FCM не должен ходить наружу (используется в соседних флоу сборки).
    $this->mock(FcmNotificationService::class)
      ->shouldReceive('sendToUser')->andReturnNull()
      ->shouldReceive('sendToUsers')->andReturnNull();

    $this->shopId = \DB::table('shops')->insertGetId([
      'title' => 'Lock shop',
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
    // (паттерн AssemblyChargePaymentTest::makeCollector).
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
      'uuid' => 'lock-' . uniqid(),
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

  private function insertProduct(): int
  {
    return \DB::table('products')->insertGetId([
      'title' => 'Lock product ' . uniqid(),
      'articul' => 'LCK-' . uniqid(),
      'unit_type_id' => 1,
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  /**
   * Собранный заказ: assembly (user_id = сборщик) + order_item + assembly_item.
   * $withExtraItem добавляет вторую ASSEMBLED-позицию — тогда отмена одной
   * позиции не уводит сборку в ветку «все отменены → заказ отменён».
   *
   * @return array{order_id:int, assembly_id:int, assembly_item_id:int, product_id:int}
   */
  private function insertAssembly(int $orderId, int $itemStatus = Status::PRODUCT_WAIT, bool $withExtraItem = false): array
  {
    $assemblyId = \DB::table('assemblies')->insertGetId([
      'order_id' => $orderId,
      'user_id' => $this->collector->id,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $productId = $this->insertProduct();

    $orderItemId = \DB::table('order_items')->insertGetId([
      'order_id' => $orderId,
      'product_id' => $productId,
      'quantity' => 1,
      'price' => 1000.0,
      'price_during_order' => 1000.0,
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $assemblyItemId = \DB::table('assembly_items')->insertGetId([
      'assembly_id' => $assemblyId,
      'order_item_id' => $orderItemId,
      'quantity' => 1,
      'status_id' => $itemStatus,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    if ($withExtraItem) {
      $extraProductId = $this->insertProduct();
      $extraOrderItemId = \DB::table('order_items')->insertGetId([
        'order_id' => $orderId,
        'product_id' => $extraProductId,
        'quantity' => 1,
        'price' => 500.0,
        'price_during_order' => 500.0,
        'status_id' => 1,
        'created_at' => now(),
        'updated_at' => now(),
      ]);
      \DB::table('assembly_items')->insert([
        'assembly_id' => $assemblyId,
        'order_item_id' => $extraOrderItemId,
        'quantity' => 1,
        'status_id' => Status::PRODUCT_ASSEMBLED,
        'created_at' => now(),
        'updated_at' => now(),
      ]);
    }

    return [
      'order_id' => $orderId,
      'assembly_id' => $assemblyId,
      'assembly_item_id' => $assemblyItemId,
      'product_id' => $productId,
    ];
  }

  private function acting(): void
  {
    $this->actingAs($this->collector, 'api');
  }

  private function rawResponse($response): string
  {
    return json_encode($response->json(), JSON_UNESCAPED_UNICODE);
  }

  // --- Негативные кейсы: paid=1, модуль ON → правки состава заблокированы ---

  /** @test */
  public function product_cancel_blocked_when_paid_online_and_module_on(): void
  {
    $this->setModule(true);
    $ids = $this->insertAssembly($this->insertOrder(['paid' => 1]));

    $this->acting();
    $response = $this->graphQL(self::M_PRODUCT_CANCEL, ['id' => $ids['assembly_item_id']]);

    $this->assertNotEmpty($response->json('errors'));
    $this->assertStringContainsString(self::LOCK_MESSAGE, $this->rawResponse($response));
  }

  /** @test */
  public function product_confirm_blocked_when_paid_online_and_module_on(): void
  {
    $this->setModule(true);
    $ids = $this->insertAssembly($this->insertOrder(['paid' => 1]));

    $this->acting();
    $response = $this->graphQL(self::M_PRODUCT_CONFIRM, [
      'assembly_item_id' => $ids['assembly_item_id'],
      'quantity' => 1,
    ]);

    $this->assertNotEmpty($response->json('errors'));
    $this->assertStringContainsString(self::LOCK_MESSAGE, $this->rawResponse($response));
  }

  /** @test */
  public function product_add_to_order_blocked_when_paid_online_and_module_on(): void
  {
    $this->setModule(true);
    $ids = $this->insertAssembly($this->insertOrder(['paid' => 1]));

    $this->acting();
    $response = $this->graphQL(self::M_PRODUCT_ADD, [
      'assembly_id' => $ids['assembly_id'],
      'product_id' => $ids['product_id'],
      'quantity' => 1,
    ]);

    $this->assertNotEmpty($response->json('errors'));
    $this->assertStringContainsString(self::LOCK_MESSAGE, $this->rawResponse($response));
  }

  // --- Позитивные кейсы: гейт не срабатывает, старое поведение сохранено ---

  /** @test */
  public function product_cancel_allowed_when_not_paid_after_402(): void
  {
    // paid=0 (доплата не прошла) — штатная корректировка состава разрешена.
    $this->setModule(true);
    $ids = $this->insertAssembly(
      $this->insertOrder(['paid' => 0, 'payment_status' => 'awaiting_surcharge']),
      Status::PRODUCT_WAIT,
      true
    );

    $this->acting();
    $response = $this->graphQL(self::M_PRODUCT_CANCEL, ['id' => $ids['assembly_item_id']]);

    $this->assertStringNotContainsString(self::LOCK_MESSAGE, $this->rawResponse($response));
    $response->assertJson(['data' => ['ProductCancel' => ['code' => 200]]]);
  }

  /** @test */
  public function product_cancel_allowed_when_module_off_even_if_paid(): void
  {
    // Модуль OFF → гейт не действует, старое поведение форков (правки идут).
    $this->setModule(false);
    $ids = $this->insertAssembly(
      $this->insertOrder(['paid' => 1]),
      Status::PRODUCT_WAIT,
      true
    );

    $this->acting();
    $response = $this->graphQL(self::M_PRODUCT_CANCEL, ['id' => $ids['assembly_item_id']]);

    $this->assertStringNotContainsString(self::LOCK_MESSAGE, $this->rawResponse($response));
    $response->assertJson(['data' => ['ProductCancel' => ['code' => 200]]]);
  }
}
