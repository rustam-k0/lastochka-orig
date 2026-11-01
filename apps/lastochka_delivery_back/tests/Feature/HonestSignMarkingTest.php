<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Assembly\MarkingCodeScan;
use App\Models\IntegrationConfig;
use App\Models\Order;
use App\Models\Role;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use App\Services\FcmNotificationService;
use App\Services\HonestSign\MarkingRequirement;
use App\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Честный знак при сборке (MID-266): скан кодов маркировки, проверка в 1С
 * (CheckCIS), гейты ProductConfirm/AssemblyFinish, очистка при возврате и
 * отмене позиции, транспорт marking_codes в событие заказа.
 *
 * Модуль honest_sign включается записью в admin_settings.project_modules,
 * креды CheckCIS — строкой integration_configs (honest_sign/onec_check_cis).
 * 1С подменяется Http::fake().
 */
class HonestSignMarkingTest extends TestCase
{
  use DatabaseTransactions, MakesGraphQLRequests;

  private const CHECK_URL = 'http://1c.honest-sign.test/Razgul/hs/Web_Exchange/CheckCIS';

  private const BARCODE = '4601234567890';

  // GS1 DataMatrix: 01 + GTIN-14 (0 + EAN-13 товара) + 21 + серийный номер
  private const CIS_OK = '010460123456789021ABCDEFGHIJKLMNOP';

  private const CIS_OK_2 = '010460123456789021QRSTUVWXYZ012345';

  // GTIN другого товара
  private const CIS_FOREIGN = '010460999999999921ZZZZZZZZZZZZZZZZ';

  // Марка без GS1-структуры (ЕГАИС-подобная): локальная сверка GTIN не применяется
  private const CIS_NO_GTIN = '22N00001A6D7Q7CFTDHSA0Y5QQAB8Z2AK';

  private const M_SCAN = '
    mutation MarkingCodeScan($assembly_item_id: Int!, $code: String!, $product_id: Int) {
      MarkingCodeScan(assembly_item_id: $assembly_item_id, code: $code, product_id: $product_id) {
        code message marking_codes { code product_id } marking_codes_required
      }
    }
  ';

  private const M_REMOVE = '
    mutation MarkingCodeRemove($assembly_item_id: Int!, $code: String!) {
      MarkingCodeRemove(assembly_item_id: $assembly_item_id, code: $code) {
        code message marking_codes { code } marking_codes_required
      }
    }
  ';

  private const M_CONFIRM = '
    mutation ProductConfirm($assembly_item_id: Int!, $quantity: Int, $replacement_product_id: Int) {
      ProductConfirm(assembly_item_id: $assembly_item_id, quantity: $quantity, replacement_product_id: $replacement_product_id) { code message }
    }
  ';

  private const M_RETURN = '
    mutation ProductReturn($id: Int!) { ProductReturn(id: $id) { code message } }
  ';

  private const M_CANCEL = '
    mutation ProductCancel($id: Int!) { ProductCancel(id: $id) { code message } }
  ';

  private const M_FINISH = '
    mutation AssemblyFinish($id: Int!, $packages: [PackageInput]) {
      AssemblyFinish(id: $id, packages: $packages) { code message }
    }
  ';

  private const Q_ITEM = '
    query($id: Int!) {
      assembly_item(id: $id) { id marking_codes_required marking_codes { code product_id } order_item { product { is_marked } } }
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

    $this->shopId = \DB::table('shops')->insertGetId([
      'title' => 'HS shop ' . uniqid(),
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
    $this->configureIntegration();
    $this->fakeOneC(true, 'Товар разрешен к продаже');
    $this->registerOneCFake();
  }

  // ---------------------------------------------------------------- фикстуры

  private function makeCollector(): User
  {
    $userId = \DB::table('users')->insertGetId([
      'name' => 'Collector', 'surname' => 'HS ' . uniqid(),
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
        'value' => json_encode(['honest_sign' => ['label' => 'Честный знак (маркировка)', 'enabled' => $enabled]]),
        'type' => 'json',
        'updated_at' => now(),
        'created_at' => now(),
      ]
    );
  }

  private function configureIntegration(): void
  {
    IntegrationConfig::updateOrCreate(
      ['integration_type' => 'honest_sign'],
      [
        'driver' => 'onec_check_cis',
        'credentials' => ['onec_check_cis' => ['url' => self::CHECK_URL, 'login' => 'Web_Exchange', 'password' => 'secret']],
        'is_active' => true,
      ]
    );
  }

  /**
   * Ответ «1С». Http::fake() накапливает стабы (первый подходящий побеждает),
   * поэтому регистрируется один динамический обработчик, а тесты меняют
   * $this->oneC.
   */
  private $oneC;

  private function fakeOneC(bool $success, string $message, int $status = 200): void
  {
    $this->oneC = Http::response(['success' => $success, 'message' => $message], $status);
  }

  private function fakeOneCRaw($responseOrCallback): void
  {
    $this->oneC = $responseOrCallback;
  }

  private function registerOneCFake(): void
  {
    Http::fake([self::CHECK_URL => function ($request) {
      return is_callable($this->oneC) ? ($this->oneC)($request) : $this->oneC;
    }]);
  }

  private function insertProduct(array $attrs = []): int
  {
    $productId = \DB::table('products')->insertGetId(array_merge([
      'title' => 'Водка ' . uniqid(),
      'articul' => 'HS-' . uniqid(),
      'unit_type_id' => 1,
      'status_id' => 1,
      'is_marked' => 1,
      'barcode' => self::BARCODE,
      'barcodes' => json_encode([self::BARCODE]),
      'created_at' => now(),
      'updated_at' => now(),
    ], $attrs));

    \DB::table('prices')->insert([
      'product_id' => $productId,
      'type_id' => Type::PRICE_DEFAULT,
      'value' => 500,
      'step' => 1,
      'blocked' => 0,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    return $productId;
  }

  private function insertOrder(array $attrs = []): int
  {
    return \DB::table('orders')->insertGetId(array_merge([
      'shop_id' => $this->shopId,
      'client_id' => $this->clientId,
      'uuid' => 'hs-' . uniqid(),
      'discount' => 0,
      'delivery_price' => 0,
      'assembly_cost' => 0,
      'bonus_spent' => 0,
      'payment_type_id' => Type::PAYMENT_CASH,
      'paid' => 0,
      'type_id' => Type::DELIVERY_COURIER,
      'status_id' => Status::ORDER_PREPARING,
      'amount' => 500,
      'created_at' => now(),
      'updated_at' => now(),
    ], $attrs));
  }

  /**
   * @return array{order_id:int, assembly_id:int, order_item_id:int, assembly_item_id:int, product_id:int}
   */
  private function insertAssembly(int $quantity = 1, array $productAttrs = [], int $itemStatus = Status::PRODUCT_WAIT, ?int $orderId = null): array
  {
    $orderId = $orderId ?? $this->insertOrder();

    $assemblyId = \DB::table('assemblies')->insertGetId([
      'order_id' => $orderId,
      'user_id' => $this->collector->id,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $productId = $this->insertProduct($productAttrs);

    $orderItemId = \DB::table('order_items')->insertGetId([
      'order_id' => $orderId,
      'product_id' => $productId,
      'quantity' => $quantity,
      'price' => 500.0 * $quantity,
      'price_during_order' => 500.0,
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $assemblyItemId = \DB::table('assembly_items')->insertGetId([
      'assembly_id' => $assemblyId,
      'order_item_id' => $orderItemId,
      'quantity' => $itemStatus === Status::PRODUCT_ASSEMBLED ? $quantity : null,
      'status_id' => $itemStatus,
      'type_id' => Type::PRODUCT_WITHOUT_PROBLEMS,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    return [
      'order_id' => $orderId,
      'assembly_id' => $assemblyId,
      'order_item_id' => $orderItemId,
      'assembly_item_id' => $assemblyItemId,
      'product_id' => $productId,
    ];
  }

  private function addAssembledItem(array $ids): int
  {
    $productId = $this->insertProduct(['is_marked' => 0]);
    $orderItemId = \DB::table('order_items')->insertGetId([
      'order_id' => $ids['order_id'],
      'product_id' => $productId,
      'quantity' => 1,
      'price' => 100.0,
      'price_during_order' => 100.0,
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    return \DB::table('assembly_items')->insertGetId([
      'assembly_id' => $ids['assembly_id'],
      'order_item_id' => $orderItemId,
      'quantity' => 1,
      'status_id' => Status::PRODUCT_ASSEMBLED,
      'type_id' => Type::PRODUCT_WITHOUT_PROBLEMS,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  private function scan(array $ids, string $code, ?int $productId = null)
  {
    $this->actingAs($this->collector, 'api');

    return $this->graphQL(self::M_SCAN, [
      'assembly_item_id' => $ids['assembly_item_id'],
      'code' => $code,
      'product_id' => $productId,
    ]);
  }

  private function codesInDb(int $assemblyItemId): array
  {
    return \DB::table('assembly_item_marking_codes')
      ->where('assembly_item_id', $assemblyItemId)
      ->orderBy('id')
      ->pluck('code')
      ->all();
  }

  private function raw($response): string
  {
    return json_encode($response->json(), JSON_UNESCAPED_UNICODE);
  }

  // ------------------------------------------------------------------ флаг

  public function test_module_flag_query(): void
  {
    $this->actingAs($this->collector, 'api');
    $this->assertTrue($this->graphQL('{ honest_sign_enabled }')->json('data.honest_sign_enabled'));

    $this->setModule(false);
    $this->assertFalse($this->graphQL('{ honest_sign_enabled }')->json('data.honest_sign_enabled'));
  }

  public function test_scan_rejected_when_module_off(): void
  {
    $this->setModule(false);
    $ids = $this->insertAssembly();

    $response = $this->scan($ids, self::CIS_OK);

    $this->assertSame(422, $response->json('data.MarkingCodeScan.code'));
    $this->assertSame(MarkingCodeScan::MODULE_DISABLED, $response->json('data.MarkingCodeScan.message'));
    $this->assertSame([], $this->codesInDb($ids['assembly_item_id']));
    Http::assertNothingSent();
  }

  // ------------------------------------------------------------------ скан

  public function test_scan_success_stores_verified_code(): void
  {
    $ids = $this->insertAssembly(quantity: 2);

    $response = $this->scan($ids, self::CIS_OK);

    $this->assertSame(200, $response->json('data.MarkingCodeScan.code'), $this->raw($response));
    $this->assertSame([self::CIS_OK], array_column($response->json('data.MarkingCodeScan.marking_codes'), 'code'));
    $this->assertSame(2, $response->json('data.MarkingCodeScan.marking_codes_required'));

    $row = \DB::table('assembly_item_marking_codes')->where('assembly_item_id', $ids['assembly_item_id'])->first();
    $this->assertNotNull($row);
    $this->assertSame($ids['order_item_id'], (int) $row->order_item_id);
    $this->assertSame($ids['product_id'], (int) $row->product_id);
    $this->assertSame(self::BARCODE, $row->barcode);
    $this->assertNotNull($row->verified_at);
    $this->assertSame('Товар разрешен к продаже', $row->verify_message);

    Http::assertSent(function ($request) {
      return $request->url() === self::CHECK_URL
        && $request['barcode'] === self::BARCODE
        && $request['cis'] === self::CIS_OK
        && $request->hasHeader('Authorization', 'Basic ' . base64_encode('Web_Exchange:secret'));
    });
  }

  public function test_scan_is_idempotent_for_same_code(): void
  {
    $ids = $this->insertAssembly();

    $this->scan($ids, self::CIS_OK);
    $response = $this->scan($ids, self::CIS_OK);

    $this->assertSame(200, $response->json('data.MarkingCodeScan.code'));
    $this->assertSame([self::CIS_OK], $this->codesInDb($ids['assembly_item_id']));
    Http::assertSentCount(1);
  }

  public function test_scan_rejected_for_not_marked_product(): void
  {
    $ids = $this->insertAssembly(productAttrs: ['is_marked' => 0]);

    $response = $this->scan($ids, self::CIS_OK);

    $this->assertSame(422, $response->json('data.MarkingCodeScan.code'));
    $this->assertSame(MarkingCodeScan::NOT_MARKED, $response->json('data.MarkingCodeScan.message'));
    $this->assertSame(0, $response->json('data.MarkingCodeScan.marking_codes_required'));
    Http::assertNothingSent();
  }

  public function test_scan_rejected_when_item_already_assembled(): void
  {
    $ids = $this->insertAssembly(itemStatus: Status::PRODUCT_ASSEMBLED);

    $response = $this->scan($ids, self::CIS_OK);

    $this->assertSame(422, $response->json('data.MarkingCodeScan.code'));
    $this->assertSame(MarkingCodeScan::ALREADY_ASSEMBLED, $response->json('data.MarkingCodeScan.message'));
    Http::assertNothingSent();
  }

  public function test_scan_rejected_by_one_c_with_its_message(): void
  {
    $this->fakeOneC(false, 'Код выведен из оборота');
    $ids = $this->insertAssembly();

    $response = $this->scan($ids, self::CIS_OK);

    $this->assertSame(422, $response->json('data.MarkingCodeScan.code'));
    $this->assertSame('Код выведен из оборота', $response->json('data.MarkingCodeScan.message'));
    $this->assertSame([], $this->codesInDb($ids['assembly_item_id']));
  }

  public function test_scan_returns_503_when_one_c_errors(): void
  {
    $this->fakeOneCRaw(Http::response('Internal error', 500));
    $ids = $this->insertAssembly();

    $response = $this->scan($ids, self::CIS_OK);

    $this->assertSame(503, $response->json('data.MarkingCodeScan.code'));
    $this->assertSame(MarkingCodeScan::SERVICE_UNAVAILABLE, $response->json('data.MarkingCodeScan.message'));
    $this->assertSame([], $this->codesInDb($ids['assembly_item_id']));
  }

  public function test_scan_returns_503_when_one_c_unreachable(): void
  {
    $this->fakeOneCRaw(function () {
      throw new ConnectionException('cURL error 28: Connection timed out');
    });
    $ids = $this->insertAssembly();

    $response = $this->scan($ids, self::CIS_OK);

    $this->assertSame(503, $response->json('data.MarkingCodeScan.code'));
    $this->assertSame([], $this->codesInDb($ids['assembly_item_id']));
  }

  public function test_scan_returns_503_when_integration_not_configured(): void
  {
    IntegrationConfig::where('integration_type', 'honest_sign')->update(['is_active' => false]);
    $ids = $this->insertAssembly();

    $response = $this->scan($ids, self::CIS_OK);

    $this->assertSame(503, $response->json('data.MarkingCodeScan.code'));
    Http::assertNothingSent();
  }

  public function test_scan_rejects_foreign_gtin_without_calling_one_c(): void
  {
    $ids = $this->insertAssembly();

    $response = $this->scan($ids, self::CIS_FOREIGN);

    $this->assertSame(422, $response->json('data.MarkingCodeScan.code'));
    $this->assertSame(MarkingCodeScan::FOREIGN_GTIN, $response->json('data.MarkingCodeScan.message'));
    Http::assertNothingSent();
  }

  public function test_scan_without_gtin_skips_local_check(): void
  {
    $ids = $this->insertAssembly();

    $response = $this->scan($ids, self::CIS_NO_GTIN);

    $this->assertSame(200, $response->json('data.MarkingCodeScan.code'), $this->raw($response));
    Http::assertSent(fn($request) => $request['barcode'] === self::BARCODE && $request['cis'] === self::CIS_NO_GTIN);
  }

  public function test_scan_rejected_when_product_has_no_barcode(): void
  {
    $ids = $this->insertAssembly(productAttrs: ['barcode' => null, 'barcodes' => null]);

    $response = $this->scan($ids, self::CIS_NO_GTIN);

    $this->assertSame(422, $response->json('data.MarkingCodeScan.code'));
    $this->assertSame(MarkingCodeScan::NO_BARCODE, $response->json('data.MarkingCodeScan.message'));
    Http::assertNothingSent();
  }

  public function test_scan_rejects_code_occupied_by_active_order_and_allows_after_cancel(): void
  {
    $other = $this->insertAssembly();
    \DB::table('assembly_item_marking_codes')->insert([
      'assembly_item_id' => $other['assembly_item_id'],
      'order_item_id' => $other['order_item_id'],
      'product_id' => $other['product_id'],
      'code' => self::CIS_OK,
      'verified_at' => now(),
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $ids = $this->insertAssembly();

    $response = $this->scan($ids, self::CIS_OK);
    $this->assertSame(422, $response->json('data.MarkingCodeScan.code'));
    $this->assertStringContainsString('уже отсканирован в заказе', (string) $response->json('data.MarkingCodeScan.message'));
    Http::assertNothingSent();

    // Код отменённого заказа освобождается
    \DB::table('orders')->where('id', $other['order_id'])->update(['status_id' => Status::ORDER_CANCELLED]);

    $response = $this->scan($ids, self::CIS_OK);
    $this->assertSame(200, $response->json('data.MarkingCodeScan.code'), $this->raw($response));
  }

  public function test_scan_for_replacement_product(): void
  {
    $ids = $this->insertAssembly();
    \DB::table('assembly_items')->where('id', $ids['assembly_item_id'])->update([
      'type_id' => Type::PRODUCT_NOT_EXISTS,
      'status_id' => Status::PRODUCT_CLARIFY,
    ]);
    $replacementId = $this->insertProduct();
    \DB::table('replacement_products')->insert([
      'assembly_item_id' => $ids['assembly_item_id'],
      'product_id' => $replacementId,
      'selected' => 0,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    // Чужой товар (не позиции и не кандидат на замену) — валидатор
    $foreign = $this->insertProduct();
    $response = $this->scan($ids, self::CIS_OK, $foreign);
    $this->assertNotEmpty($response->json('errors'));
    $this->assertStringContainsString('не относится к этой позиции', $this->raw($response));

    $response = $this->scan($ids, self::CIS_OK, $replacementId);
    $this->assertSame(200, $response->json('data.MarkingCodeScan.code'), $this->raw($response));
    $this->assertSame($replacementId, (int) \DB::table('assembly_item_marking_codes')->where('code', self::CIS_OK)->value('product_id'));

    // Подтверждение заменой: код принадлежит товару-замене — гейт пропускает
    $this->actingAs($this->collector, 'api');
    $response = $this->graphQL(self::M_CONFIRM, [
      'assembly_item_id' => $ids['assembly_item_id'],
      'quantity' => 1,
      'replacement_product_id' => $replacementId,
    ]);
    $this->assertSame(200, $response->json('data.ProductConfirm.code'), $this->raw($response));
  }

  // --------------------------------------------------------- ProductConfirm

  public function test_product_confirm_requires_one_code_per_unit(): void
  {
    $ids = $this->insertAssembly(quantity: 2);
    $this->actingAs($this->collector, 'api');

    $response = $this->graphQL(self::M_CONFIRM, ['assembly_item_id' => $ids['assembly_item_id'], 'quantity' => 2]);
    $this->assertNotEmpty($response->json('errors'));
    $this->assertStringContainsString('0 из 2', $this->raw($response));

    $this->scan($ids, self::CIS_OK);
    $response = $this->graphQL(self::M_CONFIRM, ['assembly_item_id' => $ids['assembly_item_id'], 'quantity' => 2]);
    $this->assertNotEmpty($response->json('errors'));
    $this->assertStringContainsString('1 из 2', $this->raw($response));

    $this->scan($ids, self::CIS_OK_2);
    $response = $this->graphQL(self::M_CONFIRM, ['assembly_item_id' => $ids['assembly_item_id'], 'quantity' => 2]);
    $this->assertEmpty($response->json('errors'), $this->raw($response));
    $this->assertSame(200, $response->json('data.ProductConfirm.code'));
    $this->assertSame(Status::PRODUCT_ASSEMBLED, (int) \DB::table('assembly_items')->where('id', $ids['assembly_item_id'])->value('status_id'));
  }

  public function test_product_confirm_rejects_more_codes_than_collected(): void
  {
    $ids = $this->insertAssembly(quantity: 2);
    $this->scan($ids, self::CIS_OK);
    $this->scan($ids, self::CIS_OK_2);

    $this->actingAs($this->collector, 'api');
    $response = $this->graphQL(self::M_CONFIRM, ['assembly_item_id' => $ids['assembly_item_id'], 'quantity' => 1]);

    $this->assertNotEmpty($response->json('errors'));
    $this->assertStringContainsString('больше, чем собрано', $this->raw($response));
  }

  public function test_product_confirm_not_gated_when_module_off(): void
  {
    $this->setModule(false);
    $ids = $this->insertAssembly(quantity: 2);

    $this->actingAs($this->collector, 'api');
    $response = $this->graphQL(self::M_CONFIRM, ['assembly_item_id' => $ids['assembly_item_id'], 'quantity' => 2]);

    $this->assertEmpty($response->json('errors'), $this->raw($response));
    $this->assertSame(200, $response->json('data.ProductConfirm.code'));
  }

  public function test_product_confirm_not_gated_for_not_marked_product(): void
  {
    $ids = $this->insertAssembly(quantity: 2, productAttrs: ['is_marked' => 0]);

    $this->actingAs($this->collector, 'api');
    $response = $this->graphQL(self::M_CONFIRM, ['assembly_item_id' => $ids['assembly_item_id'], 'quantity' => 2]);

    $this->assertEmpty($response->json('errors'), $this->raw($response));
    $this->assertSame(200, $response->json('data.ProductConfirm.code'));
  }

  // ------------------------------------------------- очистка и снятие кодов

  public function test_product_return_clears_codes(): void
  {
    $ids = $this->insertAssembly();
    $this->scan($ids, self::CIS_OK);
    $this->assertCount(1, $this->codesInDb($ids['assembly_item_id']));
    // ProductReturn возвращает в «Собрать» только собранную/отменённую позицию
    \DB::table('assembly_items')->where('id', $ids['assembly_item_id'])->update(['status_id' => Status::PRODUCT_ASSEMBLED, 'quantity' => 1]);

    $this->actingAs($this->collector, 'api');
    $response = $this->graphQL(self::M_RETURN, ['id' => $ids['assembly_item_id']]);

    $this->assertSame(200, $response->json('data.ProductReturn.code'), $this->raw($response));
    $this->assertSame([], $this->codesInDb($ids['assembly_item_id']));
  }

  public function test_product_cancel_clears_codes(): void
  {
    $ids = $this->insertAssembly();
    $this->addAssembledItem($ids); // чтобы отмена одной позиции не отменила весь заказ
    $this->scan($ids, self::CIS_OK);
    $this->assertCount(1, $this->codesInDb($ids['assembly_item_id']));

    $this->actingAs($this->collector, 'api');
    $response = $this->graphQL(self::M_CANCEL, ['id' => $ids['assembly_item_id']]);

    $this->assertSame(200, $response->json('data.ProductCancel.code'), $this->raw($response));
    $this->assertSame([], $this->codesInDb($ids['assembly_item_id']));
  }

  public function test_remove_deletes_code(): void
  {
    $ids = $this->insertAssembly(quantity: 2);
    $this->scan($ids, self::CIS_OK);
    $this->scan($ids, self::CIS_OK_2);

    $this->actingAs($this->collector, 'api');
    $response = $this->graphQL(self::M_REMOVE, ['assembly_item_id' => $ids['assembly_item_id'], 'code' => self::CIS_OK]);

    $this->assertSame(200, $response->json('data.MarkingCodeRemove.code'), $this->raw($response));
    $this->assertSame([self::CIS_OK_2], array_column($response->json('data.MarkingCodeRemove.marking_codes'), 'code'));
    $this->assertSame([self::CIS_OK_2], $this->codesInDb($ids['assembly_item_id']));
  }

  public function test_remove_rejected_for_assembled_item(): void
  {
    $ids = $this->insertAssembly(itemStatus: Status::PRODUCT_ASSEMBLED);

    $this->actingAs($this->collector, 'api');
    $response = $this->graphQL(self::M_REMOVE, ['assembly_item_id' => $ids['assembly_item_id'], 'code' => self::CIS_OK]);

    $this->assertSame(422, $response->json('data.MarkingCodeRemove.code'));
    $this->assertSame(MarkingCodeScan::ALREADY_ASSEMBLED, $response->json('data.MarkingCodeRemove.message'));
  }

  // ---------------------------------------------------------- AssemblyFinish

  public function test_assembly_finish_blocked_without_codes(): void
  {
    $ids = $this->insertAssembly(itemStatus: Status::PRODUCT_ASSEMBLED);

    $this->actingAs($this->collector, 'api');
    $response = $this->graphQL(self::M_FINISH, [
      'id' => $ids['assembly_id'],
      'packages' => [['type_id' => Type::PACKAGE_CABINET, 'quantity' => 1]],
    ]);

    $this->assertNotEmpty($response->json('errors'), $this->raw($response));
    $this->assertStringContainsString('Отсканируйте коды маркировки: 0 из 1', $this->raw($response));
    $this->assertSame(Status::ORDER_PREPARING, (int) \DB::table('orders')->where('id', $ids['order_id'])->value('status_id'));
  }

  public function test_assembly_requirement_satisfied_with_codes(): void
  {
    $ids = $this->insertAssembly(quantity: 2, itemStatus: Status::PRODUCT_ASSEMBLED);
    foreach ([self::CIS_OK, self::CIS_OK_2] as $code) {
      \DB::table('assembly_item_marking_codes')->insert([
        'assembly_item_id' => $ids['assembly_item_id'],
        'order_item_id' => $ids['order_item_id'],
        'product_id' => $ids['product_id'],
        'code' => $code,
        'verified_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
      ]);
    }

    $assembly = \App\Models\Assembly::withoutGlobalScopes()->findOrFail($ids['assembly_id']);

    $this->assertNull(MarkingRequirement::errorForAssembly($assembly));
  }

  // --------------------------------------------------------- GraphQL-выдача

  public function test_assembly_item_exposes_marking_fields(): void
  {
    $ids = $this->insertAssembly(quantity: 3);
    $this->scan($ids, self::CIS_OK);

    $this->actingAs($this->collector, 'api');
    $response = $this->graphQL(self::Q_ITEM, ['id' => $ids['assembly_item_id']]);

    $this->assertSame(3, $response->json('data.assembly_item.marking_codes_required'), $this->raw($response));
    $this->assertSame([self::CIS_OK], array_column($response->json('data.assembly_item.marking_codes'), 'code'));
    $this->assertTrue($response->json('data.assembly_item.order_item.product.is_marked'));
  }

  // ----------------------------------------------------- событие заказа

  public function test_order_event_includes_marking_codes(): void
  {
    $ids = $this->insertAssembly(quantity: 2);
    $this->scan($ids, self::CIS_OK);
    $this->scan($ids, self::CIS_OK_2);

    $order = Order::withoutGlobalScopes()->with(['orderItems.product', 'shop', 'client'])->findOrFail($ids['order_id']);
    $payload = app(OrderEventTransformer::class)->toEvent($order);

    $item = collect($payload['items'])->firstWhere('product_name', \DB::table('products')->where('id', $ids['product_id'])->value('title'));
    $this->assertNotNull($item, json_encode($payload['items'], JSON_UNESCAPED_UNICODE));
    $this->assertSame([self::CIS_OK, self::CIS_OK_2], $item['marking_codes']);
  }

  public function test_order_event_omits_key_without_codes(): void
  {
    $ids = $this->insertAssembly();

    $order = Order::withoutGlobalScopes()->with(['orderItems.product', 'shop', 'client'])->findOrFail($ids['order_id']);
    $payload = app(OrderEventTransformer::class)->toEvent($order);

    $this->assertArrayNotHasKey('marking_codes', $payload['items'][0]);
  }
}
