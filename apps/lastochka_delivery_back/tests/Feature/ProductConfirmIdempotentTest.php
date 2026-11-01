<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Повторное подтверждение уже собранной позиции (двойное нажатие «Собрать»,
 * повторный скан того же штрих-кода).
 *
 * Прод-кейс 19.08.2026 (заказ 353, позиция 581, Персик Инжирный): сборщик
 * подтвердил весовую позицию, ответ витал в сети 17 секунд, экран висел со
 * спиннером — сборщик нажал «Собрать» ещё три раза и каждый раз получал
 * `Validation failed for the field [_]` (внутри — «Товара нет в заказе» от
 * AssemblyItemExistsRule, который пропускает только статусы WAIT/CLARIFY).
 *
 * Ожидаемое поведение: повтор с теми же параметрами — идемпотентный 200 OK без
 * повторной записи позиции; повтор с другими параметрами — понятная ошибка
 * «Товар уже собран». Позиции в остальных статусах (отменена, чужая сборка)
 * по-прежнему отсекаются правилом.
 */
class ProductConfirmIdempotentTest extends TestCase
{
  use DatabaseTransactions, MakesGraphQLRequests;

  private const ALREADY_ASSEMBLED_MESSAGE = 'Товар уже собран';
  private const NOT_IN_ORDER_MESSAGE = 'Товара нет в заказе';

  private const M_PRODUCT_CONFIRM = '
    mutation ProductConfirm($assembly_item_id: Int!, $quantity: Int, $actual_weight: Int) {
      ProductConfirm(assembly_item_id: $assembly_item_id, quantity: $quantity, actual_weight: $actual_weight) { code message }
    }
  ';

  private int $shopId;
  private int $clientId;
  private User $collector;

  protected function setUp(): void
  {
    parent::setUp();

    $this->shopId = \DB::table('shops')->insertGetId([
      'title' => 'Idempotent shop',
      'id_1c' => random_int(900000, 999999),
      'default' => 0,
      'slot_max_orders' => 10,
      'slot_time' => 60,
      'is_popular' => 0,
      'is_pickup_enabled' => 0,
      'pickup_auto_cancel_enabled' => 0,
      'ignore_stock' => 1,
      'is_bonus_enabled' => 0,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $this->clientId = \DB::table('clients')->insertGetId([
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $this->collector = $this->makeCollector();
    $this->actingAs($this->collector, 'api');
  }

  private function makeCollector(): User
  {
    // User в этом репо полностью guarded — сырой insert + findOrFail.
    $userId = \DB::table('users')->insertGetId([
      'name' => 'Collector',
      'surname' => 'Идемпотентный ' . uniqid(),
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $user = User::findOrFail($userId);
    $user->assignRole(Role::findOrCreate('collector', 'api'));

    \DB::table('shifts')->insert([
      'user_id' => $user->id,
      'shop_id' => $this->shopId,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    return $user;
  }

  private function insertProduct(?int $infoWeight, int $infoWeightTypeId): int
  {
    return \DB::table('products')->insertGetId([
      'title' => 'PCI ' . uniqid(),
      'articul' => 'PCI-' . uniqid(),
      'unit_type_id' => Type::UNIT_PIECE,
      'info_weight' => $infoWeight,
      'info_weight_type_id' => $infoWeightTypeId,
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  /**
   * Заказ + позиция заказа + сборка сборщика + позиция сборки в статусе «собрать».
   *
   * @return array{assembly_item_id:int, order_id:int, product_id:int}
   */
  private function insertAssembly(int $productId, int $quantity, float $priceDuringOrder): array
  {
    $orderId = \DB::table('orders')->insertGetId([
      'shop_id' => $this->shopId,
      'client_id' => $this->clientId,
      'uuid' => 'pci-' . uniqid(),
      'discount' => 0,
      'payment_type_id' => Type::PAYMENT_ONLINE,
      'paid' => 0,
      'type_id' => Type::DELIVERY_COURIER,
      'status_id' => Status::ORDER_PREPARING,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $assemblyId = \DB::table('assemblies')->insertGetId([
      'order_id' => $orderId,
      'user_id' => $this->collector->id,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $orderItemId = \DB::table('order_items')->insertGetId([
      'order_id' => $orderId,
      'product_id' => $productId,
      'quantity' => $quantity,
      'price' => $priceDuringOrder * $quantity,
      'price_during_order' => $priceDuringOrder,
      'status_id' => Status::ORDER_ITEM_WAITING,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $assemblyItemId = \DB::table('assembly_items')->insertGetId([
      'assembly_id' => $assemblyId,
      'order_item_id' => $orderItemId,
      'status_id' => Status::PRODUCT_WAIT,
      'type_id' => Type::PRODUCT_WITHOUT_PROBLEMS,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    return [
      'assembly_item_id' => $assemblyItemId,
      'order_id' => $orderId,
      'product_id' => $productId,
    ];
  }

  private function assemblyItemRow(int $id): object
  {
    return \DB::table('assembly_items')->where('id', $id)->first();
  }

  private function confirm(int $assemblyItemId, array $args = [])
  {
    return $this->graphQL(self::M_PRODUCT_CONFIRM, array_merge([
      'assembly_item_id' => $assemblyItemId,
      'quantity' => null,
      'actual_weight' => null,
    ], $args));
  }

  private function rawResponse($response): string
  {
    return json_encode($response->json(), JSON_UNESCAPED_UNICODE);
  }

  /** @test */
  public function repeat_confirm_of_weight_item_with_same_weight_returns_ok_and_keeps_row(): void
  {
    // Персик Инжирный: квант 200 гр по 52.8 ₽, отсканировано 280 гр.
    $productId = $this->insertProduct(200, Type::INFO_WEIGHT_GRAM);
    $ids = $this->insertAssembly($productId, 1, 52.8);

    $this->confirm($ids['assembly_item_id'], ['actual_weight' => 280])
      ->assertJsonPath('data.ProductConfirm.code', 200);

    $afterFirst = $this->assemblyItemRow($ids['assembly_item_id']);

    $response = $this->confirm($ids['assembly_item_id'], ['actual_weight' => 280]);

    $this->assertNull($response->json('errors'), $this->rawResponse($response));
    $response->assertJsonPath('data.ProductConfirm.code', 200);

    $afterSecond = $this->assemblyItemRow($ids['assembly_item_id']);

    $this->assertSame(Status::PRODUCT_ASSEMBLED, (int) $afterSecond->status_id);
    $this->assertSame(280, (int) $afterSecond->actual_weight);
    $this->assertSame(73.92, (float) $afterSecond->price);
    // Повтор не должен перезаписывать позицию: assembled_at — база методики
    // «скан за сканом» в аналитике, а лишний update = дубль item_assembled.
    $this->assertSame($afterFirst->assembled_at, $afterSecond->assembled_at);
    $this->assertSame($afterFirst->updated_at, $afterSecond->updated_at);
  }

  /** @test */
  public function repeat_confirm_of_weight_item_with_other_weight_returns_already_assembled_error(): void
  {
    $productId = $this->insertProduct(200, Type::INFO_WEIGHT_GRAM);
    $ids = $this->insertAssembly($productId, 1, 52.8);

    $this->confirm($ids['assembly_item_id'], ['actual_weight' => 280])
      ->assertJsonPath('data.ProductConfirm.code', 200);

    $response = $this->confirm($ids['assembly_item_id'], ['actual_weight' => 300]);

    $this->assertStringContainsString(
      self::ALREADY_ASSEMBLED_MESSAGE,
      $this->rawResponse($response)
    );

    $row = $this->assemblyItemRow($ids['assembly_item_id']);
    $this->assertSame(280, (int) $row->actual_weight);
    $this->assertSame(73.92, (float) $row->price);
  }

  /** @test */
  public function repeat_confirm_of_piece_item_with_same_quantity_returns_ok(): void
  {
    $productId = $this->insertProduct(null, Type::INFO_WEIGHT_PIECE);
    $ids = $this->insertAssembly($productId, 2, 100.0);

    $this->confirm($ids['assembly_item_id'], ['quantity' => 2])
      ->assertJsonPath('data.ProductConfirm.code', 200);

    $response = $this->confirm($ids['assembly_item_id'], ['quantity' => 2]);

    $this->assertNull($response->json('errors'), $this->rawResponse($response));
    $response->assertJsonPath('data.ProductConfirm.code', 200);

    $row = $this->assemblyItemRow($ids['assembly_item_id']);
    $this->assertSame(2, (int) $row->quantity);
    $this->assertSame(200.0, (float) $row->price);
  }

  /** @test */
  public function repeat_confirm_of_piece_item_with_other_quantity_returns_already_assembled_error(): void
  {
    $productId = $this->insertProduct(null, Type::INFO_WEIGHT_PIECE);
    $ids = $this->insertAssembly($productId, 2, 100.0);

    $this->confirm($ids['assembly_item_id'], ['quantity' => 2])
      ->assertJsonPath('data.ProductConfirm.code', 200);

    $response = $this->confirm($ids['assembly_item_id'], ['quantity' => 1]);

    $this->assertStringContainsString(
      self::ALREADY_ASSEMBLED_MESSAGE,
      $this->rawResponse($response)
    );

    $row = $this->assemblyItemRow($ids['assembly_item_id']);
    $this->assertSame(2, (int) $row->quantity);
    $this->assertSame(200.0, (float) $row->price);
  }

  /** @test */
  public function confirm_of_cancelled_item_is_still_rejected(): void
  {
    // Регресс: послабление статуса касается только PRODUCT_ASSEMBLED —
    // отменённая позиция по-прежнему «Товара нет в заказе».
    $productId = $this->insertProduct(null, Type::INFO_WEIGHT_PIECE);
    $ids = $this->insertAssembly($productId, 1, 100.0);

    \DB::table('assembly_items')->where('id', $ids['assembly_item_id'])->update([
      'status_id' => Status::PRODUCT_CANCELED,
    ]);

    $response = $this->confirm($ids['assembly_item_id'], ['quantity' => 1]);

    $this->assertStringContainsString(
      self::NOT_IN_ORDER_MESSAGE,
      $this->rawResponse($response)
    );
  }
}
