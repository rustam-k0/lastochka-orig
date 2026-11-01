<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Assembly\AssemblyFinish;
use App\GraphQL\Mutations\Assembly\ProductConfirm;
use App\GraphQL\Queries\Product\ProductSearchByShop;
use App\GraphQL\Validators\Assembly\ProductAddToOrderValidator;
use App\Models\Assembly;
use App\Models\Price;
use App\Models\Product;
use App\Models\Remain;
use App\Models\Shop;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use App\Support\NetworkCatalogMode;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Nuwave\Lighthouse\Execution\Arguments\Argument;
use Nuwave\Lighthouse\Execution\Arguments\ArgumentSet;
use Tests\TestCase;

/**
 * Сетевой каталог в курьерке: карточка товара глобальная (products.shop_id = якорный
 * магазин), а цена и остаток — пер-магазин (prices.shop_id / remains.shop_id).
 *
 * Легаси (флаг выключен): товар «живёт» в своём магазине, наличие считается только
 * по остатку магазина-владельца карточки (remains.shop_id = products.shop_id).
 */
class NetworkCatalogDeliveryTest extends TestCase
{
  use DatabaseTransactions;

  private Shop $anchor;
  private Shop $shopA;
  private Shop $shopB;
  private Product $product;

  protected function setUp(): void
  {
    parent::setUp();
    Cache::forget(NetworkCatalogMode::CACHE_KEY);

    // Shop::$fillable = ['title','id_1c'] — code/default только через forceCreate.
    $this->anchor = Shop::query()->where('default', 1)->first()
      ?? Shop::forceCreate(['title' => 'Anchor', 'code' => 'dlv-anchor-' . uniqid(), 'default' => 1]);
    $this->shopA = Shop::forceCreate(['title' => 'A', 'code' => 'dlv-a-' . uniqid()]);
    $this->shopB = Shop::forceCreate(['title' => 'B', 'code' => 'dlv-b-' . uniqid()]);

    $this->setNetworkCatalog(true);

    $this->product = Product::forceCreate([
      'title' => 'Ром сетевой',
      'articul' => 'DLV-' . mb_strtoupper(uniqid()),
      'shop_id' => $this->anchor->id,
      'barcode' => '4600000000001',
      'unit_type_id' => 1,
      'status_id' => 1,
      'effective_stock_mode' => 'normal',
    ]);

    // Остаток и цена ТОЛЬКО в магазине A
    Remain::withoutGlobalScopes()->forceCreate([
      'product_id' => $this->product->id, 'shop_id' => $this->shopA->id, 'quantity' => 4,
    ]);
    (new Price())->forceFill([
      'product_id' => $this->product->id, 'shop_id' => $this->shopA->id,
      'type_id' => Type::PRICE_DEFAULT, 'value' => 700, 'step' => 1,
    ])->save();
  }

  protected function tearDown(): void
  {
    Cache::forget(NetworkCatalogMode::CACHE_KEY);
    parent::tearDown();
  }

  /** Переключить модуль network_catalog в admin_settings.project_modules и сбросить кэш. */
  private function setNetworkCatalog(bool $enabled): void
  {
    $modules = json_decode((string) DB::table('admin_settings')->where('key', 'project_modules')->value('value'), true) ?: [];
    $modules['network_catalog'] = ['label' => 'Сетевой каталог', 'enabled' => $enabled];
    DB::table('admin_settings')->updateOrInsert(
      ['key' => 'project_modules'],
      ['value' => json_encode($modules, JSON_UNESCAPED_UNICODE), 'type' => 'json', 'updated_at' => now()]
    );
    Cache::forget(NetworkCatalogMode::CACHE_KEY);
  }

  public function test_in_stock_scope_with_explicit_shop(): void
  {
    $this->assertTrue(
      Product::query()->whereKey($this->product->id)->inStock($this->shopA->id)->exists(),
      'товар в наличии в магазине A'
    );
    $this->assertFalse(
      Product::query()->whereKey($this->product->id)->inStock($this->shopB->id)->exists(),
      'в магазине B остатка нет'
    );
  }

  public function test_price_and_remain_for_shop(): void
  {
    $this->assertEquals(700.0, (float) $this->product->priceForShop($this->shopA->id)?->value);
    $this->assertNull($this->product->priceForShop($this->shopB->id));
    $this->assertSame(4, (int) $this->product->remainForShop($this->shopA->id)?->quantity);
    $this->assertNull($this->product->remainForShop($this->shopB->id));
  }

  /**
   * Флаг выключен — поведение прежнее: наличие только по остатку магазина-владельца
   * карточки. Остаток чужого магазина A товар «якоря» в наличие не переводит.
   */
  public function test_legacy_mode_keeps_owner_shop_semantics(): void
  {
    $this->setNetworkCatalog(false);

    $this->assertFalse(
      Product::query()->whereKey($this->product->id)->inStock($this->shopA->id)->exists(),
      'легаси: аргумент shopId игнорируется, остаток должен быть в магазине карточки'
    );

    // Контроль: товар магазина A с остатком в A — в наличии и в легаси.
    $ownProduct = Product::forceCreate([
      'title' => 'Ром локальный',
      'articul' => 'DLV-OWN-' . mb_strtoupper(uniqid()),
      'shop_id' => $this->shopA->id,
      'unit_type_id' => 1,
      'status_id' => 1,
      'effective_stock_mode' => 'normal',
    ]);
    Remain::withoutGlobalScopes()->forceCreate([
      'product_id' => $ownProduct->id, 'shop_id' => $this->shopA->id, 'quantity' => 2,
    ]);

    $this->assertTrue(
      Product::query()->whereKey($ownProduct->id)->inStock()->exists(),
      'легаси: остаток магазина-владельца карточки даёт наличие'
    );
  }

  /**
   * Поиск товара сборщиком: в сетевом режиме карточка якоря должна находиться
   * по заказу магазина A (остаток есть в A), а по заказу магазина B — нет.
   */
  public function test_product_search_by_shop_uses_order_shop_stock(): void
  {
    $orderInA = $this->makeOrder($this->shopA->id);
    $orderInB = $this->makeOrder($this->shopB->id);

    $query = new ProductSearchByShop();

    $foundInA = $query(null, ['order_id' => $orderInA, 'barcode' => '4600000000001']);
    $this->assertNotNull($foundInA, 'сетевой режим: карточка якоря доступна в магазине A');
    $this->assertSame($this->product->id, $foundInA->id);

    $this->assertNull(
      $query(null, ['order_id' => $orderInB, 'barcode' => '4600000000001']),
      'в магазине B остатка нет — товар не должен находиться'
    );
  }

  /**
   * C1: гейт принадлежности товара магазину заказа. В сетевом режиме карточка
   * лежит в якорном магазине — гейт не должен рубить добавление товара в сборку,
   * если в магазине заказа есть остаток и цена.
   */
  public function test_add_to_order_validator_passes_for_network_card(): void
  {
    $this->actingAsCollector();

    $assemblyInA = $this->makeAssembly($this->shopA->id);
    $this->assertSame(
      [],
      $this->runAddToOrderValidator($assemblyInA, $this->product->id),
      'сетевой режим: карточка якоря с остатком и ценой в магазине A должна проходить'
    );

    // Контроль: в магазине B ни остатка, ни цены нет — валидатор обязан зарубить.
    $assemblyInB = $this->makeAssembly($this->shopB->id);
    $this->assertNotSame(
      [],
      $this->runAddToOrderValidator($assemblyInB, $this->product->id),
      'в магазине B товар недоступен'
    );

    // Контроль легаси: с выключенным флагом тот же товар рубится гейтом
    // принадлежности (products.shop_id = якорь ≠ магазин заказа).
    $this->setNetworkCatalog(false);
    $this->assertContains(
      'Товар не принадлежит магазину заказа',
      $this->runAddToOrderValidator($assemblyInA, $this->product->id),
      'легаси: гейт принадлежности должен остаться прежним'
    );
  }

  /**
   * I2: остаток в магазине есть, а строки цены для магазина нет — должна быть
   * валидационная ошибка, а не падение ProductAddToOrder на null->value.
   */
  public function test_add_to_order_validator_fails_without_shop_price(): void
  {
    $this->actingAsCollector();

    $noPrice = $this->makeNetworkProduct('Ром без цены');
    Remain::withoutGlobalScopes()->forceCreate([
      'product_id' => $noPrice->id, 'shop_id' => $this->shopA->id, 'quantity' => 3,
    ]);

    $assemblyInA = $this->makeAssembly($this->shopA->id);

    $this->assertContains(
      'Товар не найден',
      $this->runAddToOrderValidator($assemblyInA, $noPrice->id),
      'без цены магазина заказа товар добавлять нельзя'
    );
  }

  /**
   * Легаси байт-в-байт: набор правил добавления товара в сборку не расширен.
   * Товар со статусом ≠ ACTIVE (но своего магазина, с ценой и остатком) должен
   * добавляться как раньше — гейт цены (он же onlyActive) только в сетевом режиме.
   */
  public function test_legacy_add_to_order_allows_inactive_product(): void
  {
    $this->setNetworkCatalog(false);
    $this->actingAsCollector();

    $inactive = Product::forceCreate([
      'title' => 'Ром снятый с публикации',
      'articul' => 'DLV-INACT-' . mb_strtoupper(uniqid()),
      'shop_id' => $this->shopA->id,
      'unit_type_id' => 1,
      'status_id' => Status::CODE_NOT_PUBLISHED,
      'effective_stock_mode' => 'normal',
    ]);
    $this->makePrice($inactive->id, $this->shopA->id, 500);
    Remain::withoutGlobalScopes()->forceCreate([
      'product_id' => $inactive->id, 'shop_id' => $this->shopA->id, 'quantity' => 5,
    ]);

    $assemblyInA = $this->makeAssembly($this->shopA->id);

    $this->assertSame(
      [],
      $this->runAddToOrderValidator($assemblyInA, $inactive->id),
      'легаси: статус товара добавление в сборку не блокирует'
    );
  }

  /**
   * I3: цена товара-замены берётся по магазину заказа, а не «какая-нибудь».
   * У замены две цены: 700 в магазине A и 900 в B; заказ в B → 900.
   */
  public function test_replacement_price_taken_from_order_shop(): void
  {
    $replacement = $this->makeNetworkProduct('Ром на замену');
    $this->makePrice($replacement->id, $this->shopA->id, 700);
    $this->makePrice($replacement->id, $this->shopB->id, 900);

    // Контроль настройки фикстуры: hasOne price() отдаёт цену магазина A (мин. id).
    $this->assertEquals(700.0, (float) $replacement->price->value);

    $assemblyItemId = $this->makeAssemblyItemWithReplacement($this->shopB->id, $replacement->id)['assembly_item_id'];

    (new ProductConfirm())->__invoke(null, [
      'assembly_item_id' => $assemblyItemId,
      'replacement_product_id' => $replacement->id,
      'quantity' => 2,
    ]);

    // assembly_item.price — итог за позицию: 900 (цена магазина B) × 2.
    $this->assertEquals(
      1800.0,
      (float) DB::table('assembly_items')->where('id', $assemblyItemId)->value('price'),
      'цена замены должна быть взята из магазина заказа (B = 900), а не из A (700)'
    );
  }

  /**
   * N1, легаси байт-в-байт: у товара-замены нет ни одной цены. До сетевого
   * каталога это давало нулевую цену позиции (null-коэрсия), а не исключение —
   * поведение должно сохраниться, throw появляется только в сетевом режиме.
   */
  public function test_legacy_replacement_without_price_keeps_zero(): void
  {
    $this->setNetworkCatalog(false);

    $replacement = $this->makeNetworkProduct('Ром без единой цены');
    $assemblyItemId = $this->makeAssemblyItemWithReplacement($this->shopB->id, $replacement->id)['assembly_item_id'];

    (new ProductConfirm())->__invoke(null, [
      'assembly_item_id' => $assemblyItemId,
      'replacement_product_id' => $replacement->id,
      'quantity' => 2,
    ]);

    $this->assertEquals(
      0.0,
      (float) DB::table('assembly_items')->where('id', $assemblyItemId)->value('price'),
      'легаси: замена без цены даёт нулевую цену позиции, без исключения'
    );
  }

  /**
   * N1, сетевой режим: та же ситуация — явная ошибка, а не молчаливый ноль.
   */
  public function test_network_replacement_without_shop_price_throws(): void
  {
    $replacement = $this->makeNetworkProduct('Ром без цены магазина');
    // Цена есть, но в чужом магазине — в магазине заказа (B) её нет.
    $this->makePrice($replacement->id, $this->shopA->id, 700);

    $assemblyItemId = $this->makeAssemblyItemWithReplacement($this->shopB->id, $replacement->id)['assembly_item_id'];

    $this->expectException(\GraphQL\Error\Error::class);
    $this->expectExceptionMessage('У товара для замены нет цены в магазине заказа');

    (new ProductConfirm())->__invoke(null, [
      'assembly_item_id' => $assemblyItemId,
      'replacement_product_id' => $replacement->id,
      'quantity' => 2,
    ]);
  }

  /**
   * N1 (раунд 4), сетевой режим: гейт цены замены именно в AssemblyFinish.
   * Замена уже подтверждена (selected=1), сборка доводится до финиша через
   * AssemblyFinish::assemblyFinish() → collectOrderItems(). У товара-замены
   * есть цена в магазине A, но нет в магазине заказа B → явная ошибка.
   */
  public function test_assembly_finish_network_replacement_without_shop_price_throws(): void
  {
    $replacement = $this->makeNetworkProduct('Ром замена без цены B');
    $this->makePrice($replacement->id, $this->shopA->id, 700);

    $ids = $this->makeAssemblyItemWithReplacement($this->shopB->id, $replacement->id, true);

    $this->expectException(\GraphQL\Error\Error::class);
    $this->expectExceptionMessage('У товара для замены нет цены в магазине заказа');

    AssemblyFinish::assemblyFinish(Assembly::findOrFail($ids['assembly_id']));
  }

  /**
   * N1 (раунд 4), легаси байт-в-байт: та же сборка доводится до финиша без
   * исключения, order_items.price_during_order остаётся null (прежняя
   * null-коэрсия), цена позиции — 0.
   */
  public function test_assembly_finish_legacy_replacement_without_price_keeps_null(): void
  {
    $this->setNetworkCatalog(false);

    $replacement = $this->makeNetworkProduct('Ром замена без единой цены');
    $ids = $this->makeAssemblyItemWithReplacement($this->shopB->id, $replacement->id, true);

    AssemblyFinish::assemblyFinish(Assembly::findOrFail($ids['assembly_id']));

    $row = DB::table('order_items')->where('id', $ids['order_item_id'])->first();

    $this->assertNull($row->price_during_order, 'легаси: цена замены отсутствует → null, без исключения');
    $this->assertEquals(0.0, (float) $row->price, 'легаси: нулевая цена позиции, как было');
    $this->assertSame(
      $replacement->id,
      (int) $row->product_id,
      'сборка реально дошла до применения замены в collectOrderItems'
    );
  }

  /**
   * Легаси: цена замены берётся как раньше — связь price() без shop-фильтра.
   */
  public function test_replacement_price_legacy_uses_plain_price_relation(): void
  {
    $this->setNetworkCatalog(false);

    $replacement = $this->makeNetworkProduct('Ром легаси');
    $this->makePrice($replacement->id, $this->shopA->id, 700);
    $this->makePrice($replacement->id, $this->shopB->id, 900);

    $this->assertEquals(
      700.0,
      (float) $replacement->effectivePriceForShop($this->shopB->id)?->value,
      'легаси: shop-контекст не применяется, отдаётся прежняя связь price()'
    );
  }

  /** Минимальный заказ для ProductSearchByShop. */
  private function makeOrder(int $shopId): int
  {
    $clientId = DB::table('clients')->insertGetId([
      'is_authorized' => 1,
      'newsletter_subscribed' => 0,
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    return DB::table('orders')->insertGetId([
      'shop_id' => $shopId,
      'uuid' => 'net-catalog-' . uniqid(),
      'payment_type_id' => 1,
      'type_id' => 2,
      'client_id' => $clientId,
      'status_id' => 2,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  /**
   * Заказ в магазине + сборка + позиция типа «товара нет» с предложенной заменой.
   *
   * @param bool $selected true — замена сразу помечена выбранной (как после
   *                       ProductConfirm), чтобы довести сборку до AssemblyFinish
   *                       без прохода через мутацию подтверждения.
   * @return array{order_id:int, order_item_id:int, assembly_id:int, assembly_item_id:int}
   */
  private function makeAssemblyItemWithReplacement(int $shopId, int $replacementProductId, bool $selected = false): array
  {
    $orderId = $this->makeOrder($shopId);
    $collectorId = $this->actingAsCollector();

    $orderItemId = DB::table('order_items')->insertGetId([
      'order_id' => $orderId,
      'product_id' => $this->product->id,
      'quantity' => 2,
      'price' => 200,
      'price_during_order' => 100,
      'status_id' => Status::ORDER_ITEM_WAITING,
      'is_supplemented' => 0,
      'is_eligible' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
    $assemblyId = DB::table('assemblies')->insertGetId([
      'order_id' => $orderId,
      'user_id' => $collectorId,
      'finished_at' => null,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
    $assemblyItemId = DB::table('assembly_items')->insertGetId([
      'assembly_id' => $assemblyId,
      'order_item_id' => $orderItemId,
      'status_id' => Status::PRODUCT_WAIT,
      'type_id' => Type::PRODUCT_NOT_EXISTS,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
    DB::table('replacement_products')->insert([
      'product_id' => $replacementProductId,
      'assembly_item_id' => $assemblyItemId,
      'selected' => $selected ? 1 : 0,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    return [
      'order_id' => $orderId,
      'order_item_id' => $orderItemId,
      'assembly_id' => $assemblyId,
      'assembly_item_id' => $assemblyItemId,
    ];
  }

  /** Сборка (с заказом) в указанном магазине, принадлежит текущему пользователю. */
  private function makeAssembly(int $shopId): int
  {
    return DB::table('assemblies')->insertGetId([
      'order_id' => $this->makeOrder($shopId),
      'user_id' => auth()->id(),
      'finished_at' => null,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  /** Сборщик под Auth — Assembly::auth() фильтрует по user_id. */
  private function actingAsCollector(): int
  {
    $userId = DB::table('users')->insertGetId([
      'name' => 'Сборщиков',
      'surname' => 'Сетевой',
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);
    $this->actingAs(User::find($userId));

    return $userId;
  }

  /** Глобальная карточка товара (принадлежит якорному магазину). */
  private function makeNetworkProduct(string $title): Product
  {
    return Product::forceCreate([
      'title' => $title,
      'articul' => 'DLV-' . mb_strtoupper(uniqid()),
      'shop_id' => $this->anchor->id,
      'unit_type_id' => 1,
      'status_id' => 1,
      'effective_stock_mode' => 'normal',
    ]);
  }

  private function makePrice(int $productId, int $shopId, float $value): void
  {
    (new Price())->forceFill([
      'product_id' => $productId, 'shop_id' => $shopId,
      'type_id' => Type::PRICE_DEFAULT, 'value' => $value, 'step' => 1,
    ])->save();
  }

  /**
   * Прогоняет ВЕСЬ набор правил ProductAddToOrderValidator (и closure, и Rule-объекты)
   * через обычный Laravel-валидатор. Возвращает список сообщений об ошибках.
   *
   * @return array<int, string>
   */
  private function runAddToOrderValidator(int $assemblyId, int $productId): array
  {
    $validator = new ProductAddToOrderValidator();

    $argSet = new ArgumentSet();
    foreach (['assembly_id' => $assemblyId, 'product_id' => $productId, 'quantity' => 1] as $name => $value) {
      $argument = new Argument();
      $argument->value = $value;
      $argSet->arguments[$name] = $argument;
    }
    $validator->setArgs($argSet);

    return Validator::make(
      ['assembly_id' => $assemblyId, 'product_id' => $productId, 'quantity' => 1],
      $validator->rules()
    )->errors()->all();
  }
}
