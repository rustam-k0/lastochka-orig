<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Order\OrderShopUpdate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Type;
use App\Rules\RemainExistsRule;
use App\Support\NetworkCatalogMode;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Сетевой каталог в purchase_back: у товара один «якорь» products.shop_id,
 * а цены и остатки лежат пер-магазин. Всё, что раньше брало «первую» цену
 * или остаток «текущего магазина клиента», обязано считаться в контексте
 * магазина ЗАКАЗА. Легаси-режим (флаг выключен) должен вести себя как раньше.
 */
class NetworkCatalogPurchaseTest extends TestCase
{
  use DatabaseTransactions;

  private int $shopAId;
  private int $shopBId;
  private int $clientId;
  private Product $product;

  protected function setUp(): void
  {
    parent::setUp();

    Cache::forget(NetworkCatalogMode::CACHE_KEY);

    $this->shopAId = $this->makeShop('NCP shop A', true);
    $this->shopBId = $this->makeShop('NCP shop B', false);
    $this->clientId = $this->makeClient();

    // Карточка товара «принадлежит» магазину A (якорь), продаётся в обоих.
    $this->product = Product::findOrFail($this->makeProduct('Джин сетевой', $this->shopAId));

    $this->makePrice($this->product->id, $this->shopAId, 300);
    $this->makePrice($this->product->id, $this->shopBId, 380);

    $this->makeRemain($this->product->id, $this->shopAId, 10);
    $this->makeRemain($this->product->id, $this->shopBId, 0);
  }

  protected function tearDown(): void
  {
    Cache::forget(NetworkCatalogMode::CACHE_KEY);

    parent::tearDown();
  }

  // --- фикстуры ------------------------------------------------------------

  private function makeShop(string $title, bool $isDefault): int
  {
    return (int) DB::table('shops')->insertGetId([
      'title' => $title . ' ' . uniqid(),
      'code' => 'ncp-' . uniqid(),
      'id_1c' => random_int(900000, 999999),
      'default' => $isDefault ? 1 : 0,
      'slot_max_orders' => 10,
      'slot_time' => 60,
      'is_popular' => 0,
      'is_pickup_enabled' => 0,
      'pickup_auto_cancel_enabled' => 0,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  private function makeClient(): int
  {
    return (int) DB::table('clients')->insertGetId([
      'password' => bcrypt('secret'),
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  private function makeProduct(string $title, ?int $shopId): int
  {
    return (int) DB::table('products')->insertGetId([
      'title' => $title . ' ' . uniqid(),
      'shop_id' => $shopId,
      'articul' => 'NCP-' . uniqid(),
      'unit_type_id' => Type::UNIT_PIECE,
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  private function makePrice(int $productId, int $shopId, float $value): int
  {
    return (int) DB::table('prices')->insertGetId([
      'product_id' => $productId,
      'shop_id' => $shopId,
      'type_id' => Type::PRICE_DEFAULT,
      'value' => $value,
      'step' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  private function makeRemain(int $productId, int $shopId, int $quantity): int
  {
    return (int) DB::table('remains')->insertGetId([
      'product_id' => $productId,
      'shop_id' => $shopId,
      'quantity' => $quantity,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  private function makeOrder(int $shopId): Order
  {
    return Order::factory()->create([
      'shop_id' => $shopId,
      'client_id' => $this->clientId,
      'payment_type_id' => 1,
      'status_id' => \App\Models\Status::ORDER_CREATED,
    ]);
  }

  /**
   * Фикстура повторяет реальную модель данных (сверено на dev-БД: 152/152
   * позиции имеют price == price_during_order, origin_quantity NULL в 146/152):
   * order_items.price — цена ЗА ЕДИНИЦУ, а не итог позиции. Итог считает
   * потребитель: OrderEventTransformer публикует line_total = price * quantity.
   */
  private function makeOrderItem(Order $order, Product $product, int $quantity, float $unitPrice, float $priceOld = null): OrderItem
  {
    $id = (int) DB::table('order_items')->insertGetId([
      'order_id' => $order->id,
      'product_id' => $product->id,
      'quantity' => $quantity,
      'origin_quantity' => null,
      'price' => $unitPrice,
      'price_during_order' => $unitPrice,
      'price_old' => $priceOld,
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    return OrderItem::findOrFail($id);
  }

  /**
   * Весовой товар с фасовкой (UNIT_GRAM, база кг, порция 300 г) — как 982
   * товара прода Ласточки. Цена позиции — prices.value (за порцию), а не
   * price_per_kg (за кг).
   */
  private function makeWeightProduct(): Product
  {
    $id = (int) DB::table('products')->insertGetId([
      'title' => 'Филе весовое ' . uniqid(),
      'shop_id' => $this->shopAId,
      'articul' => 'NCPW-' . uniqid(),
      'unit_type_id' => Type::UNIT_GRAM,
      'base_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM,
      'info_weight' => 300,
      'info_weight_type_id' => Type::INFO_WEIGHT_GRAM,
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    return Product::findOrFail($id);
  }

  private function makeWeightPrice(int $productId, int $shopId, float $value, ?float $pricePerKg): int
  {
    return (int) DB::table('prices')->insertGetId([
      'product_id' => $productId,
      'shop_id' => $shopId,
      'type_id' => Type::PRICE_DEFAULT,
      'value' => $value,
      'price_per_kg' => $pricePerKg,
      'step' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  private function setNetworkCatalog(bool $enabled): void
  {
    $modules = json_decode(
      (string) DB::table('admin_settings')->where('key', 'project_modules')->value('value'),
      true
    ) ?: [];

    $modules['network_catalog'] = ['label' => 'Сетевой каталог', 'enabled' => $enabled];

    DB::table('admin_settings')->updateOrInsert(
      ['key' => 'project_modules'],
      ['value' => json_encode($modules, JSON_UNESCAPED_UNICODE), 'type' => 'json', 'updated_at' => now()]
    );

    Cache::forget(NetworkCatalogMode::CACHE_KEY);
  }

  // --- цена/остаток по магазину -------------------------------------------

  public function test_price_for_shop_returns_shop_price(): void
  {
    $this->assertEquals(300.0, (float) $this->product->priceForShop($this->shopAId)?->value);
    $this->assertEquals(380.0, (float) $this->product->priceForShop($this->shopBId)?->value);
  }

  public function test_price_for_shop_returns_null_when_product_not_sold_in_shop(): void
  {
    $foreignShopId = $this->makeShop('NCP shop C', false);

    $this->assertNull($this->product->priceForShop($foreignShopId));
  }

  public function test_remain_for_shop_returns_shop_remain(): void
  {
    $this->assertSame(10, (int) $this->product->remainForShop($this->shopAId)?->quantity);
    $this->assertSame(0, (int) $this->product->remainForShop($this->shopBId)?->quantity);
  }

  public function test_remain_for_shop_ignores_soft_deleted_remain(): void
  {
    $foreignShopId = $this->makeShop('NCP shop D', false);
    $remainId = $this->makeRemain($this->product->id, $foreignShopId, 5);
    DB::table('remains')->where('id', $remainId)->update(['deleted_at' => now()]);

    $this->assertNull($this->product->remainForShop($foreignShopId));
  }

  public function test_get_auth_price_uses_passed_shop_in_network_mode(): void
  {
    $this->setNetworkCatalog(true);

    $this->assertEquals(380.0, (float) $this->product->getAuthPrice($this->shopBId)?->value);
    $this->assertEquals(300.0, (float) $this->product->getAuthPrice($this->shopAId)?->value);
  }

  public function test_get_auth_price_ignores_shop_in_legacy_mode(): void
  {
    $this->setNetworkCatalog(false);

    // Легаси: первая цена карточки, магазин не учитывается (поведение не менялось).
    $this->assertEquals(300.0, (float) $this->product->getAuthPrice($this->shopBId)?->value);
    $this->assertEquals(300.0, (float) $this->product->getAuthPrice()?->value);
  }

  public function test_get_auth_remains_uses_passed_shop_in_network_mode(): void
  {
    $this->setNetworkCatalog(true);

    $this->assertSame(0, (int) $this->product->getAuthRemains($this->shopBId)?->quantity);
    $this->assertSame(10, (int) $this->product->getAuthRemains($this->shopAId)?->quantity);
  }

  public function test_get_auth_remains_falls_back_to_default_shop_in_legacy_mode(): void
  {
    $this->setNetworkCatalog(false);

    // Без клиента bindShopQuery уходит на магазин по умолчанию — это shop A.
    $this->assertSame(10, (int) $this->product->getAuthRemains()?->quantity);
    $this->assertSame(10, (int) $this->product->getAuthRemains($this->shopBId)?->quantity);
  }

  // --- RemainExistsRule ----------------------------------------------------

  public function test_remain_rule_uses_order_shop_remain_in_network_mode(): void
  {
    $this->setNetworkCatalog(true);

    $orderA = $this->makeOrder($this->shopAId);
    $orderB = $this->makeOrder($this->shopBId);

    $ruleA = new RemainExistsRule($orderA->id, 1);
    $ruleB = new RemainExistsRule($orderB->id, 1);

    $this->assertTrue($ruleA->passes('product_id', $this->product->id), 'в магазине A остаток 10');
    $this->assertFalse($ruleB->passes('product_id', $this->product->id), 'в магазине B остатка нет');
  }

  public function test_remain_rule_rejects_product_without_price_in_order_shop(): void
  {
    $this->setNetworkCatalog(true);

    $shopCId = $this->makeShop('NCP shop E', false);
    $this->makeRemain($this->product->id, $shopCId, 100);
    $orderC = $this->makeOrder($shopCId);

    $rule = new RemainExistsRule($orderC->id, 1);

    $this->assertFalse($rule->passes('product_id', $this->product->id));
    $this->assertStringContainsString('магазин', mb_strtolower($rule->message()));
  }

  public function test_remain_rule_explicit_shop_argument_wins(): void
  {
    $this->setNetworkCatalog(true);

    $orderA = $this->makeOrder($this->shopAId);

    $rule = new RemainExistsRule($orderA->id, 1, $this->shopBId);

    $this->assertFalse($rule->passes('product_id', $this->product->id), 'явный магазин B — остатка нет');
  }

  public function test_remain_rule_legacy_mode_uses_default_shop(): void
  {
    $this->setNetworkCatalog(false);

    // Легаси: остаток магазина по умолчанию (A = 10), магазин заказа не важен.
    $orderB = $this->makeOrder($this->shopBId);
    $rule = new RemainExistsRule($orderB->id, 1);

    $this->assertTrue($rule->passes('product_id', $this->product->id));
  }

  // --- ProductsAddToOrderValidator (проброс магазина заказа в правило) ------

  public function test_add_to_order_rejects_product_without_price_in_order_shop(): void
  {
    $this->setNetworkCatalog(true);

    $shopGId = $this->makeShop('NCP shop G', false);
    $this->makeRemain($this->product->id, $shopGId, 100);
    $order = $this->makeOrder($shopGId);

    $response = $this->addProducts($order->id, $this->product->id, 1);

    $this->assertStringContainsString(
      'Товар недоступен в магазине заказа',
      json_encode($response->json(), JSON_UNESCAPED_UNICODE)
    );
  }

  public function test_add_to_order_rejects_product_without_remain_in_order_shop(): void
  {
    $this->setNetworkCatalog(true);

    // В магазине B цена есть, а остатка нет — валидатор обязан смотреть
    // остаток магазина заказа, а не магазина по умолчанию (A, где остаток 10).
    $order = $this->makeOrder($this->shopBId);

    $response = $this->addProducts($order->id, $this->product->id, 1);

    $this->assertStringContainsString(
      'Нет остатков для данного товара',
      json_encode($response->json(), JSON_UNESCAPED_UNICODE)
    );
  }

  private function addProducts(int $orderId, int $productId, int $quantity)
  {
    $query = sprintf(
      'mutation { ProductsAddToOrder(order_id: %d, products: [{product_id: %d, quantity: %d}]) { code message } }',
      $orderId,
      $productId,
      $quantity
    );

    return $this->postJson('/graphql-purchase', ['query' => $query], [
      'Authorization' => 'Bearer ' . $this->makeStaffToken(),
    ]);
  }

  private function makeStaffToken(): string
  {
    $role = \App\Models\Role::findOrCreate('role_network_catalog_purchase_test', 'api');

    $userId = (int) DB::table('users')->insertGetId([
      'name' => 'NCP',
      'email' => uniqid('ncp_') . '@test.local',
      'password' => bcrypt('secret'),
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $user = \App\Models\User::withoutGlobalScope('onlyAdmin')->findOrFail($userId);
    $user->assignRole($role);

    return $user->createToken('test')->plainTextToken;
  }

  // --- OrderShopUpdate -----------------------------------------------------

  public function test_order_shop_update_recalculates_item_prices(): void
  {
    $this->setNetworkCatalog(true);
    Http::fake(['*' => Http::response(['code' => 200, 'message' => 'OK'], 200)]);

    $order = $this->makeOrder($this->shopAId);
    $item = $this->makeOrderItem($order, $this->product, 3, 300.0, 350.0);

    $result = app(OrderShopUpdate::class)(null, [
      'order_id' => $order->id,
      'shop_id' => $this->shopBId,
    ]);

    $this->assertSame(200, $result['code']);
    $fresh = $item->fresh();
    $this->assertEquals(380.0, (float) $fresh->price_during_order, 'цена за единицу пересчитана по новому магазину');
    $this->assertEquals(380.0, (float) $fresh->price, 'price — тоже ЗА ЕДИНИЦУ (line_total считает потребитель как price * quantity)');
    $this->assertNull($fresh->price_old, 'зачёркнутая цена старого магазина сброшена');
  }

  public function test_order_shop_update_recalculates_order_totals(): void
  {
    $this->setNetworkCatalog(true);
    Http::fake(['*' => Http::response(['code' => 200, 'message' => 'OK'], 200)]);

    $order = $this->makeOrder($this->shopAId);
    DB::table('orders')->where('id', $order->id)->update([
      'discount' => 100,
      'bonus_spent' => 50,
      'delivery_price' => 200,
      'assembly_cost' => 30,
    ]);

    $this->makeOrderItem($order, $this->product, 3, 300.0);

    app(OrderShopUpdate::class)(null, [
      'order_id' => $order->id,
      'shop_id' => $this->shopBId,
    ]);

    // 3 * 380 = 1140 товаров; 1140 - 100 - 50 + 200 + 30 = 1220
    $fresh = $order->fresh();
    $this->assertEquals(1140.0, (float) $fresh->items_subtotal, 'сумма товаров по новому магазину');
    $this->assertEquals(1220.0, (float) $fresh->amount, 'итог заказа по формуле AssemblyFinish');

  }

  /**
   * delivery_back публикует order.updated ДО пересчёта (со старыми ценами),
   * поэтому итоги обязаны сохраняться через Eloquent — на «eloquent.updated:
   * Order» висит ModelEventObserver пакета rabbitmq-events, который пишет
   * order.updated в event_outbox. Проверяем именно этот хук, а не таблицу
   * outbox: пакет регистрирует наблюдателей один раз на процесс
   * (RabbitMQEventsServiceProvider::$observersRegistered — static), поэтому
   * в тестах после первого они уже не навешиваются на свежий диспетчер.
   */
  public function test_order_shop_update_fires_eloquent_updated_for_outbox(): void
  {
    $this->setNetworkCatalog(true);
    Http::fake(['*' => Http::response(['code' => 200, 'message' => 'OK'], 200)]);

    $order = $this->makeOrder($this->shopAId);
    $this->makeOrderItem($order, $this->product, 3, 300.0);

    $fired = [];
    Event::listen('eloquent.updated: ' . Order::class, function (Order $updated) use (&$fired) {
      $fired[] = (float) $updated->amount;
    });

    app(OrderShopUpdate::class)(null, [
      'order_id' => $order->id,
      'shop_id' => $this->shopBId,
    ]);

    $this->assertNotEmpty($fired, 'сохранение итогов прошло через Eloquent (outbox получит order.updated)');
    $this->assertContains(1140.0, $fired, 'в событие уехал пересчитанный итог');
  }

  /**
   * Опасная ветка: итог совпал со старым ДО последнего знака. Eloquent
   * считает такой update «чистым» (originalIsEquivalent → is_numeric + strcmp,
   * а "800.55" === (string) 800.55) и updated не выстреливает сам — событие
   * обязан поднять код. Тест с круглым итогом (800.0 против "800.00") эту
   * ветку НЕ проверяет: там строки всегда разные и событие уходит штатно.
   */
  public function test_order_shop_update_fires_event_when_total_matches_to_the_cent(): void
  {
    $this->setNetworkCatalog(true);
    Http::fake(['*' => Http::response(['code' => 200, 'message' => 'OK'], 200)]);

    $cheap = Product::findOrFail($this->makeProduct('Бурбон сетевой', $this->shopAId));
    $this->makePrice($cheap->id, $this->shopAId, 300.25);
    $this->makePrice($cheap->id, $this->shopBId, 380.55);

    $pricey = Product::findOrFail($this->makeProduct('Текила сетевая', $this->shopAId));
    $this->makePrice($pricey->id, $this->shopAId, 500.30);
    $this->makePrice($pricey->id, $this->shopBId, 420.00);

    // 300.25 + 500.30 = 800.55 и 380.55 + 420.00 = 800.55 — итог не меняется
    $order = $this->makeOrder($this->shopAId);
    DB::table('orders')->where('id', $order->id)
      ->update(['amount' => 800.55, 'items_subtotal' => 800.55]);

    $first = $this->makeOrderItem($order, $cheap, 1, 300.25);
    $this->makeOrderItem($order, $pricey, 1, 500.30);

    $fired = 0;
    Event::listen('eloquent.updated: ' . Order::class, function () use (&$fired) {
      $fired++;
    });

    app(OrderShopUpdate::class)(null, [
      'order_id' => $order->id,
      'shop_id' => $this->shopBId,
    ]);

    $fresh = $order->fresh();
    $this->assertEquals(380.55, (float) $first->fresh()->price_during_order, 'цены позиций изменились');
    $this->assertEquals(800.55, (float) $fresh->items_subtotal, 'итог совпал со старым до копейки');
    $this->assertEquals(800.55, (float) $fresh->amount);
    $this->assertGreaterThan(0, $fired, 'order.updated обязан уйти даже при совпавшем до копейки итоге');
  }

  public function test_order_shop_update_fires_event_even_when_total_unchanged(): void
  {
    $this->setNetworkCatalog(true);
    Http::fake(['*' => Http::response(['code' => 200, 'message' => 'OK'], 200)]);

    // Вторая позиция дешевеет ровно на столько, на сколько дорожает первая:
    // итог заказа не меняется, но цены позиций — да, клиенту событие нужно.
    $second = Product::findOrFail($this->makeProduct('Ром сетевой', $this->shopAId));
    $this->makePrice($second->id, $this->shopAId, 500);
    $this->makePrice($second->id, $this->shopBId, 420);

    $order = $this->makeOrder($this->shopAId);
    DB::table('orders')->where('id', $order->id)->update(['amount' => 800, 'items_subtotal' => 800]);

    $first = $this->makeOrderItem($order, $this->product, 1, 300.0);
    $this->makeOrderItem($order, $second, 1, 500.0);

    $fired = 0;
    Event::listen('eloquent.updated: ' . Order::class, function () use (&$fired) {
      $fired++;
    });

    app(OrderShopUpdate::class)(null, [
      'order_id' => $order->id,
      'shop_id' => $this->shopBId,
    ]);

    $this->assertEquals(380.0, (float) $first->fresh()->price_during_order, 'цены позиций всё же изменились');
    $this->assertEquals(800.0, (float) $order->fresh()->items_subtotal, 'итог совпал со старым');
    $this->assertGreaterThan(0, $fired, 'событие ушло даже при совпавшем итоге');
  }

  public function test_order_shop_update_excludes_canceled_items_from_totals(): void
  {
    $this->setNetworkCatalog(true);
    Http::fake(['*' => Http::response(['code' => 200, 'message' => 'OK'], 200)]);

    $order = $this->makeOrder($this->shopAId);
    $live = $this->makeOrderItem($order, $this->product, 2, 300.0);
    $canceled = $this->makeOrderItem($order, $this->product, 5, 300.0);
    DB::table('order_items')->where('id', $canceled->id)
      ->update(['status_id' => \App\Models\Status::ORDER_ITEM_CANCELED]);

    app(OrderShopUpdate::class)(null, [
      'order_id' => $order->id,
      'shop_id' => $this->shopBId,
    ]);

    $this->assertEquals(380.0, (float) $live->fresh()->price_during_order);
    $this->assertEquals(300.0, (float) $canceled->fresh()->price_during_order, 'отменённую позицию не перецениваем');
    $this->assertEquals(760.0, (float) $order->fresh()->items_subtotal, 'отменённая позиция в сумму не входит');
  }

  public function test_order_shop_update_keeps_price_when_product_absent_in_new_shop(): void
  {
    $this->setNetworkCatalog(true);
    Http::fake(['*' => Http::response(['code' => 200, 'message' => 'OK'], 200)]);

    $shopCId = $this->makeShop('NCP shop F', false);

    $order = $this->makeOrder($this->shopAId);
    $item = $this->makeOrderItem($order, $this->product, 2, 300.0);

    $result = app(OrderShopUpdate::class)(null, [
      'order_id' => $order->id,
      'shop_id' => $shopCId,
    ]);

    $this->assertSame(200, $result['code'], 'отсутствие цены не роняет смену магазина');
    $this->assertEquals(300.0, (float) $item->fresh()->price_during_order, 'цена осталась прежней');
    $this->assertEquals(600.0, (float) $order->fresh()->items_subtotal, 'непересчитанная позиция входит в сумму по старой цене');
  }

  // --- весовые товары: цена за порцию (prices.value) ------------------------

  public function test_order_shop_update_recalculates_weight_item_by_portion_price(): void
  {
    $this->setNetworkCatalog(true);
    Http::fake(['*' => Http::response(['code' => 200, 'message' => 'OK'], 200)]);

    // Порция 300 г: 178.20 (594 ₽/кг) в магазине A, 195.00 (650 ₽/кг) в B.
    $product = $this->makeWeightProduct();
    $this->makeWeightPrice($product->id, $this->shopAId, 178.2, 594.0);
    $this->makeWeightPrice($product->id, $this->shopBId, 195.0, 650.0);

    $order = $this->makeOrder($this->shopAId);
    $item = $this->makeOrderItem($order, $product, 2, 178.2);

    app(OrderShopUpdate::class)(null, [
      'order_id' => $order->id,
      'shop_id' => $this->shopBId,
    ]);

    $fresh = $item->fresh();
    $this->assertEquals(195.0, (float) $fresh->price_during_order, 'цена за порцию 300 г, а не за кг');
    $this->assertEquals(195.0, (float) $fresh->price);
    $this->assertEquals(390.0, (float) $order->fresh()->items_subtotal, '2 порции × 195');
  }

  /**
   * Позиция со взвешенным весом: итог считается не quantity, а фактическим
   * весом — той же формулой, что в OrderEventTransformer (line_total по
   * actual_weight / step_grams). Ветка достижима: смена магазина разрешена
   * в статусе «на сборке», сборка удаляется, а order_items.actual_weight
   * от уже взвешенных позиций остаётся.
   */
  public function test_order_shop_update_uses_actual_weight_for_totals(): void
  {
    $this->setNetworkCatalog(true);
    Http::fake(['*' => Http::response(['code' => 200, 'message' => 'OK'], 200)]);

    $product = $this->makeWeightProduct();
    $this->makeWeightPrice($product->id, $this->shopAId, 178.2, 594.0);
    $this->makeWeightPrice($product->id, $this->shopBId, 195.0, 650.0);

    $order = $this->makeOrder($this->shopAId);
    $item = $this->makeOrderItem($order, $product, 2, 178.2);
    DB::table('order_items')->where('id', $item->id)->update(['actual_weight' => 528]);

    app(OrderShopUpdate::class)(null, [
      'order_id' => $order->id,
      'shop_id' => $this->shopBId,
    ]);

    $this->assertEquals(195.0, (float) $item->fresh()->price_during_order);
    // 195 × 528 / 300 = 343.20 — по весу и с копейками, а не 195 × quantity(2)
    $this->assertEquals(343.2, (float) $order->fresh()->items_subtotal, 'итог по фактическому весу');
  }

  public function test_order_shop_update_reprices_weight_item_without_price_per_kg(): void
  {
    $this->setNetworkCatalog(true);
    Http::fake(['*' => Http::response(['code' => 200, 'message' => 'OK'], 200)]);

    $product = $this->makeWeightProduct();
    $this->makeWeightPrice($product->id, $this->shopAId, 178.2, 594.0);
    // price_per_kg в новом магазине пуст (на dev такие строки есть) — цене
    // за порцию он не нужен, позиция всё равно пересчитывается по value.
    $this->makeWeightPrice($product->id, $this->shopBId, 195.0, null);

    $order = $this->makeOrder($this->shopAId);
    $item = $this->makeOrderItem($order, $product, 2, 178.2);

    $result = app(OrderShopUpdate::class)(null, [
      'order_id' => $order->id,
      'shop_id' => $this->shopBId,
    ]);

    $this->assertSame(200, $result['code']);
    $this->assertEquals(195.0, (float) $item->fresh()->price_during_order);
    $this->assertEquals(390.0, (float) $order->fresh()->items_subtotal);
  }

  public function test_order_shop_update_keeps_price_for_unknown_unit_type(): void
  {
    $this->setNetworkCatalog(true);
    Http::fake(['*' => Http::response(['code' => 200, 'message' => 'OK'], 200)]);

    // OrderCreatorService::getPriceDuringOrder на неизвестном типе единицы
    // бросает \Error — он не должен утаскивать за собой весь пересчёт.
    $productId = $this->makeProduct('Неизвестная единица', $this->shopAId);
    DB::table('products')->where('id', $productId)->update(['unit_type_id' => 99]);
    $product = Product::findOrFail($productId);
    $this->makePrice($product->id, $this->shopAId, 100);
    $this->makePrice($product->id, $this->shopBId, 150);

    $order = $this->makeOrder($this->shopAId);
    $item = $this->makeOrderItem($order, $product, 2, 100.0);

    $result = app(OrderShopUpdate::class)(null, [
      'order_id' => $order->id,
      'shop_id' => $this->shopBId,
    ]);

    $this->assertSame(200, $result['code']);
    $this->assertEquals(100.0, (float) $item->fresh()->price_during_order, 'цена осталась прежней');
    $this->assertEquals(200.0, (float) $order->fresh()->items_subtotal, 'позиция вошла в сумму по старой цене');
  }

  // --- транзакционность ----------------------------------------------------

  public function test_order_shop_update_rolls_back_all_items_on_hard_failure(): void
  {
    $this->setNetworkCatalog(true);
    Http::fake(['*' => Http::response(['code' => 200, 'message' => 'OK'], 200)]);

    $second = Product::findOrFail($this->makeProduct('Виски сетевой', $this->shopAId));
    $this->makePrice($second->id, $this->shopAId, 500);
    $this->makePrice($second->id, $this->shopBId, 600);

    $order = $this->makeOrder($this->shopAId);
    $first = $this->makeOrderItem($order, $this->product, 1, 300.0);
    $failing = $this->makeOrderItem($order, $second, 1, 500.0);

    // Жёсткий сбой на второй позиции: заказ не должен остаться с ценами двух магазинов
    Event::listen(
      'eloquent.updating: ' . OrderItem::class,
      function (OrderItem $item) use ($failing) {
        if ($item->id === $failing->id) {
          throw new \RuntimeException('boom');
        }
      }
    );

    $result = app(OrderShopUpdate::class)(null, [
      'order_id' => $order->id,
      'shop_id' => $this->shopBId,
    ]);

    $this->assertSame(200, $result['code'], 'сбой пересчёта не роняет мутацию');
    $this->assertEquals(300.0, (float) $first->fresh()->price_during_order, 'первая позиция откатилась');
    $this->assertEquals(500.0, (float) $failing->fresh()->price_during_order, 'вторая позиция не изменилась');
  }

  public function test_order_shop_update_does_not_touch_prices_in_legacy_mode(): void
  {
    $this->setNetworkCatalog(false);
    Http::fake(['*' => Http::response(['code' => 200, 'message' => 'OK'], 200)]);

    $order = $this->makeOrder($this->shopAId);
    $item = $this->makeOrderItem($order, $this->product, 2, 300.0);

    app(OrderShopUpdate::class)(null, [
      'order_id' => $order->id,
      'shop_id' => $this->shopBId,
    ]);

    $this->assertEquals(300.0, (float) $item->fresh()->price_during_order, 'легаси-режим цены не трогает');
    $this->assertEquals(300.0, (float) $item->fresh()->price);
    $this->assertNull($order->fresh()->items_subtotal, 'легаси-режим итоги заказа не трогает');
  }

  public function test_order_shop_update_does_not_recalculate_on_failed_response(): void
  {
    $this->setNetworkCatalog(true);
    Http::fake(['*' => Http::response(null, 500)]);

    $order = $this->makeOrder($this->shopAId);
    $item = $this->makeOrderItem($order, $this->product, 2, 300.0);

    $result = app(OrderShopUpdate::class)(null, [
      'order_id' => $order->id,
      'shop_id' => $this->shopBId,
    ]);

    $this->assertSame(500, $result['code']);
    $this->assertEquals(300.0, (float) $item->fresh()->price_during_order, 'магазин не сменился — цены не трогаем');
  }
}
