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
 * Повторное завершение сборки (заказ 195-2, прод 2026-09-10).
 *
 * Сборщик свайпнул «Списать оплату», запрос ушёл в ЮKassa и выполнялся 3 секунды.
 * Второй запрос по той же сборке пришёл, когда первый уже всё завершил: сборка
 * скрыта глобальным скоупом NotFinished, валидатор отвечал «Сборка не найдена»,
 * а мобилка показывала это как «Увы! Платеж не прошел» — при том, что оплата
 * прошла и заказ был собран.
 *
 * Повтор обязан быть идемпотентным: успех, без второго списания холда, без
 * второго пакета и без повторного списания складского остатка.
 */
class AssemblyFinishIdempotencyTest extends TestCase
{
  use DatabaseTransactions, MakesGraphQLRequests;

  private int $shopId;
  private int $clientId;
  private User $collector;

  private const MUTATION = '
    mutation AssemblyFinish($id: Int!, $packages: [PackageInput]) {
      AssemblyFinish(id: $id, packages: $packages) { code message }
    }
  ';

  protected function setUp(): void
  {
    parent::setUp();

    $this->mock(FcmNotificationService::class)
      ->shouldReceive('sendToUser')->andReturnNull()
      ->shouldReceive('sendToUsers')->andReturnNull();

    $this->shopId = \DB::table('shops')->insertGetId([
      'title' => 'Idempotency shop',
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

    // Назначение курьера после сборки ищет роль courier — в тестовой БД её может не быть.
    Role::findOrCreate('courier', 'api');

    $this->collector = $this->makeCollector();
  }

  /** @test */
  public function repeated_finish_returns_success_instead_of_assembly_not_found(): void
  {
    $this->setCollectorPaymentModule(false);
    [$orderId, $assemblyId] = $this->insertAssembly($this->insertOrder());

    $first = $this->finish($assemblyId, $orderId);
    $first->assertJson(['data' => ['AssemblyFinish' => ['code' => 200]]]);

    $second = $this->finish($assemblyId, $orderId);

    $this->assertEmpty(
      $second->json('errors'),
      'повтор по завершённой сборке не должен отвечать «Сборка не найдена»: '
        . json_encode($second->json('errors'), JSON_UNESCAPED_UNICODE)
    );
    $second->assertJson(['data' => ['AssemblyFinish' => ['code' => 200]]]);
  }

  /** @test */
  public function repeated_finish_does_not_duplicate_packages(): void
  {
    $this->setCollectorPaymentModule(false);
    [$orderId, $assemblyId] = $this->insertAssembly($this->insertOrder());

    $this->finish($assemblyId, $orderId);
    $this->finish($assemblyId, $orderId);

    $packages = \DB::table('assembly_packages')->where('assembly_id', $assemblyId)->count();

    $this->assertSame(1, $packages, 'повтор не должен создавать второй пакет');
  }

  /** @test */
  public function repeated_finish_does_not_charge_payment_twice(): void
  {
    // Гонка на проде: два параллельных завершения дали два capture одного холда
    // (payment_transactions 210 и 211, оба 13:22:29).
    $this->setCollectorPaymentModule(true);

    Http::fake([
      '*' => Http::response(['status' => 'ok'], 200),
    ]);

    [$orderId, $assemblyId] = $this->insertAssembly(
      $this->insertOrder(['payment_type_id' => Type::PAYMENT_ONLINE, 'paid' => 0])
    );

    $this->finish($assemblyId, $orderId);
    $this->finish($assemblyId, $orderId);

    $settleCalls = 0;
    Http::recorded(function ($request) use (&$settleCalls) {
      if (str_contains($request->url(), 'payment')) {
        $settleCalls++;
      }

      return true;
    });

    $this->assertSame(1, $settleCalls, 'списание оплаты должно уйти в storefront ровно один раз');
  }

  /** @test */
  public function repeated_finish_keeps_order_assembled(): void
  {
    $this->setCollectorPaymentModule(false);
    [$orderId, $assemblyId] = $this->insertAssembly($this->insertOrder());

    $this->finish($assemblyId, $orderId);
    $this->finish($assemblyId, $orderId);

    $this->assertSame(
      Status::ORDER_ASSEMBLED,
      (int) \DB::table('orders')->where('id', $orderId)->value('status_id')
    );
  }

  /** @test */
  public function missing_assembly_still_fails(): void
  {
    $this->setCollectorPaymentModule(false);
    $orderId = $this->insertOrder();

    $response = $this->finish(999999999, $orderId);

    $this->assertNotEmpty($response->json('errors'), 'несуществующая сборка обязана оставаться ошибкой');
    $this->assertStringContainsString(
      'Сборка не найдена',
      json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE)
    );
  }

  /** @test */
  public function repeated_rest_finish_from_admin_returns_success(): void
  {
    // Админка («Следующий шаг») ходит тем же путём в обход GraphQL-мутации.
    config(['services.system.token' => 'test-token']);
    $this->setCollectorPaymentModule(false);
    [$orderId] = $this->insertAssembly($this->insertOrder());

    $first = $this->post('/backend-delivery/order/assembly-finish', [
      'token' => 'test-token',
      'order_id' => $orderId,
    ]);
    $first->assertStatus(200);

    $second = $this->post('/backend-delivery/order/assembly-finish', [
      'token' => 'test-token',
      'order_id' => $orderId,
    ]);

    $second->assertStatus(200);
    $this->assertSame(
      Status::ORDER_ASSEMBLED,
      (int) \DB::table('orders')->where('id', $orderId)->value('status_id')
    );
  }

  /** @test */
  public function rest_finish_still_404_when_no_assembly(): void
  {
    config(['services.system.token' => 'test-token']);
    $this->setCollectorPaymentModule(false);
    $orderId = $this->insertOrder();

    $response = $this->post('/backend-delivery/order/assembly-finish', [
      'token' => 'test-token',
      'order_id' => $orderId,
    ]);

    $response->assertStatus(404);
  }

  // ---------------------------------------------------------------------
  // helpers
  // ---------------------------------------------------------------------

  private function finish(int $assemblyId, int $orderId)
  {
    $this->actingAs($this->collector, 'api');

    return $this->graphQL(self::MUTATION, [
      'id' => $assemblyId,
      'packages' => [['type_id' => Type::PACKAGE_FREEZER, 'quantity' => 1]],
    ]);
  }

  private function makeCollector(): User
  {
    $userId = \DB::table('users')->insertGetId([
      'name' => 'Collector', 'surname' => 'Идемпотентный ' . uniqid(),
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

  private function setCollectorPaymentModule(bool $enabled): void
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
      'uuid' => 'idem-' . uniqid(),
      'discount' => 0,
      'delivery_price' => 0,
      'assembly_cost' => 0,
      'bonus_spent' => 0,
      'payment_type_id' => Type::PAYMENT_OFFLINE,
      'paid' => 0,
      'type_id' => Type::DELIVERY_COURIER,
      'status_id' => Status::ORDER_PREPARING,
      'amount' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ], $attrs));
  }

  /** @return array{0:int,1:int,2:int} */
  private function insertAssembly(int $orderId): array
  {
    $assemblyId = \DB::table('assemblies')->insertGetId([
      'order_id' => $orderId,
      'user_id' => $this->collector->id,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $productId = \DB::table('products')->insertGetId([
      'title' => 'Idempotency product ' . uniqid(),
      'articul' => 'IDEM-' . uniqid(),
      'unit_type_id' => 1,
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

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

    \DB::table('assembly_items')->insert([
      'assembly_id' => $assemblyId,
      'order_item_id' => $orderItemId,
      'quantity' => 1,
      'status_id' => Status::PRODUCT_ASSEMBLED,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    return [$orderId, $assemblyId, $productId];
  }
}
