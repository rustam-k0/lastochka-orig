<?php

namespace Tests\Feature;

use App\GraphQL\Queries\Product\ProductSearchByShop;
use App\Models\Price;
use App\Models\Product;
use App\Models\Remain;
use App\Models\Shop;
use App\Models\Type;
use App\Models\User;
use App\Support\NetworkCatalogMode;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Поиск товара сборщиком для товаров в режиме «остаток не блокирует»
 * (effective_stock_mode = ignore|fixed).
 *
 * Товары собственного производства (хлеб, выпечка, супы «Ласточка») стоят на полке,
 * но в 1С по ним приходит нулевой остаток — приход такой номенклатуры не
 * регистрируется. Для них админка выставляет режим ignore/fixed, и вся цепочка
 * сборки (ProductAddToOrder, ProductAddToOrderValidator, ProductHasStockRule,
 * AssemblyFinish) проверку наличия для них пропускает.
 *
 * Поиск же (product_search_by_shop / product_global_search) шёл со скоупом inStock,
 * который требовал remains.quantity > 0 и режим товара не смотрел: добавить товар
 * в заказ система разрешала, а найти его сборщик не мог («Товар не найден,
 * пожалуйста проверьте введённый артикул» на «Хлеб Серый На Опаре Ласточка»).
 */
class ProductSearchFixedStockModeTest extends TestCase
{
  use DatabaseTransactions, MakesGraphQLRequests;

  private Shop $shop;

  protected function setUp(): void
  {
    parent::setUp();

    $this->setNetworkCatalog(false);

    $this->shop = Shop::forceCreate(['title' => 'Fixed stock shop', 'code' => 'fs-shop-' . uniqid()]);
  }

  protected function tearDown(): void
  {
    Cache::forget(NetworkCatalogMode::CACHE_KEY);
    parent::tearDown();
  }

  // ---------------------------------------------------------------------
  // Легаси-режим (products.shop_id = магазин заказа)
  // ---------------------------------------------------------------------

  public function test_search_by_shop_finds_fixed_stock_product_with_zero_remain(): void
  {
    $bread = $this->makeProduct([
      'title' => 'Хлеб Серый На Опаре Ласточка',
      'articul' => '172 484',
      'code' => '172 484',
      'barcode' => '2100100060817',
      'effective_stock_mode' => 'fixed',
    ], remain: 0);

    $found = (new ProductSearchByShop())(null, [
      'order_id' => $this->makeOrder(),
      'articul' => '172 484',
    ]);

    $this->assertNotNull($found, 'товар в режиме fixed должен находиться при нулевом остатке');
    $this->assertSame($bread->id, $found->id);
  }

  public function test_search_by_shop_finds_ignore_stock_product_without_remain_row(): void
  {
    $product = $this->makeProduct([
      'title' => 'Суп Чечевичный Ласточка',
      'barcode' => '2100100058357',
      'effective_stock_mode' => 'ignore',
    ], remain: null);

    $found = (new ProductSearchByShop())(null, [
      'order_id' => $this->makeOrder(),
      'barcode' => '2100100058357',
    ]);

    $this->assertNotNull($found, 'товар в режиме ignore должен находиться и без строки остатка');
    $this->assertSame($product->id, $found->id);
  }

  public function test_search_by_shop_still_hides_normal_product_with_zero_remain(): void
  {
    $this->makeProduct([
      'title' => 'Обычный товар без остатка',
      'articul' => 'NORMAL-ZERO-' . uniqid(),
      'barcode' => '4600000000017',
      'effective_stock_mode' => 'normal',
    ], remain: 0);

    $found = (new ProductSearchByShop())(null, [
      'order_id' => $this->makeOrder(),
      'barcode' => '4600000000017',
    ]);

    $this->assertNull($found, 'обычный товар с нулевым остатком по-прежнему не находится');
  }

  public function test_product_global_search_graphql_finds_fixed_stock_product(): void
  {
    $bread = $this->makeProduct([
      'title' => 'Багет Ласточка',
      'articul' => 'BAGUETTE-' . uniqid(),
      'barcode' => '2100100060824',
      'effective_stock_mode' => 'fixed',
    ], remain: 0);

    $this->actingAsCollectorOnShift();

    $response = $this->graphQL(sprintf(
      '{ product_global_search(barcode: "%s") { id title } }',
      '2100100060824'
    ));

    $response->assertJson([
      'data' => [
        'product_global_search' => [
          'id' => $bread->id,
          'title' => 'Багет Ласточка',
        ],
      ],
    ]);
  }

  public function test_product_global_search_graphql_still_hides_normal_product_with_zero_remain(): void
  {
    $this->makeProduct([
      'title' => 'Обычный товар без остатка (global)',
      'articul' => 'NORMAL-GLOBAL-' . uniqid(),
      'barcode' => '4600000000024',
      'effective_stock_mode' => 'normal',
    ], remain: 0);

    $this->actingAsCollectorOnShift();

    $response = $this->graphQL('{ product_global_search(barcode: "4600000000024") { id title } }');

    $response->assertJson(['data' => ['product_global_search' => null]]);
  }

  // ---------------------------------------------------------------------
  // Артикул/код с неразрывным пробелом от 1С («172 484» на ценнике — «172484» с клавиатуры)
  // ---------------------------------------------------------------------

  public function test_search_by_shop_finds_product_when_articul_typed_without_separator(): void
  {
    $bread = $this->makeProduct([
      'title' => 'Хлеб Серый На Опаре Ласточка',
      'articul' => "172\u{A0}484",
      'code' => "172\u{A0}484",
      'barcode' => '2100100060855',
      'effective_stock_mode' => 'fixed',
    ], remain: 0);

    $found = (new ProductSearchByShop())(null, [
      'order_id' => $this->makeOrder(),
      'articul' => '172484',
    ]);

    $this->assertNotNull($found, 'цифры с ценника, введённые слитно, должны находить товар с «172 484» в базе');
    $this->assertSame($bread->id, $found->id);
  }

  public function test_search_by_shop_finds_product_when_articul_typed_with_plain_space(): void
  {
    $bread = $this->makeProduct([
      'title' => 'Батон Ласточка',
      'articul' => "27\u{A0}386",
      'barcode' => '2100100060862',
      'effective_stock_mode' => 'fixed',
    ], remain: 0);

    $found = (new ProductSearchByShop())(null, [
      'order_id' => $this->makeOrder(),
      'articul' => '27 386',
    ]);

    $this->assertNotNull($found, 'обычный пробел с клавиатуры должен матчить неразрывный пробел из 1С');
    $this->assertSame($bread->id, $found->id);
  }

  public function test_search_by_shop_finds_normalized_articul_when_db_is_already_clean(): void
  {
    $product = $this->makeProduct([
      'title' => 'Товар с чистым артикулом',
      'articul' => '172485',
      'barcode' => '2100100060879',
      'effective_stock_mode' => 'fixed',
    ], remain: 0);

    $found = (new ProductSearchByShop())(null, [
      'order_id' => $this->makeOrder(),
      'articul' => "172\u{A0}485",
    ]);

    $this->assertNotNull($found, 'после чистки данных ввод с пробелом обязан находить слитный артикул');
    $this->assertSame($product->id, $found->id);
  }

  public function test_search_by_shop_does_not_match_foreign_articul(): void
  {
    $this->makeProduct([
      'title' => 'Чужой товар',
      'articul' => "172\u{A0}484",
      'barcode' => '2100100060886',
      'effective_stock_mode' => 'fixed',
    ], remain: 0);

    $found = (new ProductSearchByShop())(null, [
      'order_id' => $this->makeOrder(),
      'articul' => '172 48',
    ]);

    $this->assertNull($found, 'нормализация не должна превращать поиск в поиск по подстроке');
  }

  public function test_search_by_shop_finds_product_by_1c_code_printed_on_price_tag(): void
  {
    // На ценнике печатается «Код» 1С, а не артикул: у большинства товаров это
    // разные значения, и сборщик вводит именно код.
    $product = $this->makeProduct([
      'title' => 'Совок Этна С Кромкой Серый',
      'articul' => '175756',
      'code' => "119\u{A0}360",
      'barcode' => '4690290103312',
      'effective_stock_mode' => 'normal',
    ], remain: 3);

    $found = (new ProductSearchByShop())(null, [
      'order_id' => $this->makeOrder(),
      'articul' => '119360',
    ]);

    $this->assertNotNull($found, 'код с ценника должен находить товар, даже если артикул у него другой');
    $this->assertSame($product->id, $found->id);
  }

  public function test_search_by_shop_prefers_price_tag_code_over_foreign_articul(): void
  {
    // Артикула на ценнике нет, сборщик вводит «Код» 1С. На проде у 536 товаров
    // артикул совпадает с кодом другого товара: побеждать должен тот, чей код
    // напечатан на ценнике, иначе сборщик получает чужой товар.
    $this->makeProduct([
      'title' => 'Сыр Сулугуни Кубанский Гостинец 250г',
      'articul' => '98715',
      'code' => "103\u{A0}644",
      'barcode' => '4600000000048',
      'effective_stock_mode' => 'normal',
    ], remain: 2);

    $byCode = $this->makeProduct([
      'title' => 'Паста Томатная Довгань 700г',
      'articul' => 'PASTE-' . uniqid(),
      'code' => "98\u{A0}715",
      'barcode' => '4600000000055',
      'effective_stock_mode' => 'normal',
    ], remain: 2);

    $found = (new ProductSearchByShop())(null, [
      'order_id' => $this->makeOrder(),
      'articul' => '98715',
    ]);

    $this->assertNotNull($found);
    $this->assertSame($byCode->id, $found->id, 'код с ценника приоритетнее совпадения по чужому артикулу');
  }

  public function test_search_by_shop_still_finds_product_by_articul_when_no_code_matches(): void
  {
    $product = $this->makeProduct([
      'title' => 'Набор Контейнеров Лайт',
      'articul' => '158666',
      'code' => "103\u{A0}644",
      'barcode' => '4600000000062',
      'effective_stock_mode' => 'normal',
    ], remain: 2);

    $found = (new ProductSearchByShop())(null, [
      'order_id' => $this->makeOrder(),
      'articul' => '158666',
    ]);

    $this->assertNotNull($found, 'артикул из карточки товара в приложении по-прежнему находит товар');
    $this->assertSame($product->id, $found->id);
  }

  // ---------------------------------------------------------------------
  // PLU весового товара: на ценнике «PLU: 00030», 1С пока кладёт его в «Код»
  // ---------------------------------------------------------------------

  public function test_search_by_shop_finds_weighted_product_by_zero_padded_plu_while_1c_sends_it_as_code(): void
  {
    // Текущая выгрузка 1С: у весовых в реквизите «Код» лежит PLU весов без нулей.
    $pepper = $this->makeProduct([
      'title' => 'Перец Болгарский Красный',
      'articul' => '91535',
      'code' => '30',
      'plu' => null,
      'barcode' => '0000000000030',
      'effective_stock_mode' => 'normal',
    ], remain: 5);

    $found = (new ProductSearchByShop())(null, [
      'order_id' => $this->makeOrder(),
      'articul' => '00030',
    ]);

    $this->assertNotNull($found, 'PLU с ценника с ведущими нулями должен находить весовой товар');
    $this->assertSame($pepper->id, $found->id);
  }

  public function test_search_by_shop_finds_weighted_product_by_plu_field(): void
  {
    // Выгрузка после доработки 1С: настоящий код номенклатуры в «Код», PLU отдельно.
    $pepper = $this->makeProduct([
      'title' => 'Перец Болгарский Красный',
      'articul' => '91535',
      'code' => '52026',
      'plu' => '00030',
      'barcode' => '0000000000030',
      'effective_stock_mode' => 'normal',
    ], remain: 5);

    $order = $this->makeOrder();

    foreach (['00030', '30', '52026', "52\u{A0}026"] as $typed) {
      $found = (new ProductSearchByShop())(null, ['order_id' => $order, 'articul' => $typed]);

      $this->assertNotNull($found, "«{$typed}» с ценника должен находить весовой товар");
      $this->assertSame($pepper->id, $found->id);
    }
  }

  public function test_search_by_shop_prefers_plu_over_foreign_code_for_zero_padded_input(): void
  {
    $pepper = $this->makeProduct([
      'title' => 'Перец Болгарский Красный',
      'articul' => '91535',
      'code' => '52026',
      'plu' => '30',
      'barcode' => '0000000000030',
      'effective_stock_mode' => 'normal',
    ], remain: 5);

    // Суп собственного производства с коротким кодом 1С, равным PLU перца.
    $this->makeProduct([
      'title' => 'Суп Чечевичный 650г Ласточка',
      'articul' => 'SOUP-' . uniqid(),
      'code' => '30',
      'barcode' => '2100100058357',
      'effective_stock_mode' => 'fixed',
    ], remain: 0);

    $found = (new ProductSearchByShop())(null, [
      'order_id' => $this->makeOrder(),
      'articul' => '00030',
    ]);

    $this->assertNotNull($found);
    $this->assertSame($pepper->id, $found->id, 'в формате PLU с ценника («00030») побеждает PLU, а не чужой код');
  }

  public function test_search_by_shop_does_not_strip_leading_zeros_from_articul(): void
  {
    $this->makeProduct([
      'title' => 'Шоколад Эко Ботаника Без Сахара 90г',
      'articul' => '000000001',
      'code' => '9' . random_int(10000, 99999),
      'barcode' => '4600000000079',
      'effective_stock_mode' => 'normal',
    ], remain: 2);

    $found = (new ProductSearchByShop())(null, [
      'order_id' => $this->makeOrder(),
      'articul' => '1',
    ]);

    $this->assertNull($found, 'ведущие нули отбрасываются только у кода и PLU, артикул сравнивается как есть');
  }

  public function test_weighted_barcode_prefers_plu_over_foreign_code(): void
  {
    $pepper = $this->makeProduct([
      'title' => 'Перец Болгарский Красный',
      'articul' => '91535',
      'code' => '52026',
      'plu' => '30',
      'barcode' => '0000000000030',
      'effective_stock_mode' => 'normal',
    ], remain: 5);

    $this->makeProduct([
      'title' => 'Суп Чечевичный 650г Ласточка',
      'articul' => 'SOUP-' . uniqid(),
      'code' => '30',
      'barcode' => '2100100058357',
      'effective_stock_mode' => 'fixed',
    ], remain: 0);

    // Весы печатают префикс 23 + PLU «00030» + вес 1.234 кг + контрольная цифра.
    $found = (new ProductSearchByShop())(null, [
      'order_id' => $this->makeOrder(),
      'barcode' => '2300030012345',
    ]);

    $this->assertNotNull($found);
    $this->assertSame($pepper->id, $found->id, 'весовой штрихкод несёт PLU весов, поэтому PLU приоритетнее кода');
  }

  public function test_product_global_search_graphql_returns_1c_code_next_to_articul(): void
  {
    $product = $this->makeProduct([
      'title' => 'Совок Этна С Кромкой Серый',
      'articul' => '175756',
      'code' => "119\u{A0}360",
      'plu' => null,
      'barcode' => '4690290103312',
      'effective_stock_mode' => 'normal',
    ], remain: 3);

    $this->actingAsCollectorOnShift();

    $response = $this->graphQL('{ product_global_search(barcode: "4690290103312") { id articul code plu } }');

    $response->assertJson([
      'data' => [
        'product_global_search' => [
          'id' => $product->id,
          'articul' => '175756',
          'code' => '119360',
          'plu' => null,
        ],
      ],
    ]);
  }

  public function test_weighted_barcode_matches_code_stored_with_separator(): void
  {
    // Весовой штрихкод: 23 + код «00296» + вес; в базе код лежит как «29 6»? нет —
    // проверяем 5-значный код 1С «17 248», записанный 1С с неразрывным пробелом.
    $scales = $this->makeProduct([
      'title' => 'Сыр Весовой Ласточка',
      'articul' => 'SCALES-' . uniqid(),
      'code' => "17\u{A0}248",
      'barcode' => '0000000017248',
      'effective_stock_mode' => 'normal',
    ], remain: 5);

    $found = (new ProductSearchByShop())(null, [
      'order_id' => $this->makeOrder(),
      'barcode' => '2317248012345',
    ]);

    $this->assertNotNull($found, 'код из весового штрихкода должен матчить products.code с разделителем разрядов');
    $this->assertSame($scales->id, $found->id);
  }

  // ---------------------------------------------------------------------
  // Сетевой каталог: карточка глобальная, принадлежность магазину даёт цена
  // ---------------------------------------------------------------------

  public function test_network_catalog_finds_fixed_stock_product_priced_in_order_shop(): void
  {
    $this->setNetworkCatalog(true);

    $anchor = Shop::forceCreate(['title' => 'Anchor shop', 'code' => 'fs-anchor-' . uniqid()]);

    $bread = $this->makeProduct([
      'title' => 'Хлеб Сетевой Ласточка',
      'articul' => 'NET-FIXED-' . uniqid(),
      'barcode' => '2100100060831',
      'shop_id' => $anchor->id,
      'effective_stock_mode' => 'fixed',
    ], remain: null);

    $this->makePrice($bread, $this->shop->id);

    $found = (new ProductSearchByShop())(null, [
      'order_id' => $this->makeOrder(),
      'barcode' => '2100100060831',
    ]);

    $this->assertNotNull($found, 'сетевой режим: fixed-товар с ценой в магазине заказа должен находиться');
    $this->assertSame($bread->id, $found->id);
  }

  public function test_network_catalog_hides_fixed_stock_product_not_sold_in_order_shop(): void
  {
    $this->setNetworkCatalog(true);

    $anchor = Shop::forceCreate(['title' => 'Anchor shop', 'code' => 'fs-anchor2-' . uniqid()]);
    $foreign = Shop::forceCreate(['title' => 'Foreign shop', 'code' => 'fs-foreign-' . uniqid()]);

    $bread = $this->makeProduct([
      'title' => 'Хлеб Чужого Магазина',
      'articul' => 'NET-FOREIGN-' . uniqid(),
      'barcode' => '2100100060848',
      'shop_id' => $anchor->id,
      'effective_stock_mode' => 'fixed',
    ], remain: null);

    // Цена есть, но только в чужом магазине — в магазине заказа товар не продаётся.
    $this->makePrice($bread, $foreign->id);

    $found = (new ProductSearchByShop())(null, [
      'order_id' => $this->makeOrder(),
      'barcode' => '2100100060848',
    ]);

    $this->assertNull($found, 'сетевой режим: fixed-товар без цены в магазине заказа не должен «протекать» из чужого магазина');
  }

  public function test_network_catalog_still_hides_normal_product_with_zero_remain(): void
  {
    $this->setNetworkCatalog(true);

    $anchor = Shop::forceCreate(['title' => 'Anchor shop', 'code' => 'fs-anchor3-' . uniqid()]);

    $product = $this->makeProduct([
      'title' => 'Сетевой товар без остатка',
      'articul' => 'NET-NORMAL-' . uniqid(),
      'barcode' => '4600000000031',
      'shop_id' => $anchor->id,
      'effective_stock_mode' => 'normal',
    ], remain: null);

    Remain::withoutGlobalScopes()->forceCreate([
      'product_id' => $product->id,
      'shop_id' => $this->shop->id,
      'quantity' => 0,
    ]);
    $this->makePrice($product, $this->shop->id);

    $found = (new ProductSearchByShop())(null, [
      'order_id' => $this->makeOrder(),
      'barcode' => '4600000000031',
    ]);

    $this->assertNull($found, 'сетевой режим: обычный товар с нулевым остатком по-прежнему не находится');
  }

  // ---------------------------------------------------------------------
  // helpers
  // ---------------------------------------------------------------------

  /**
   * @param int|null $remain Остаток в магазине заказа; null — строки остатка нет вовсе.
   */
  private function makeProduct(array $attributes, ?int $remain): Product
  {
    $product = Product::forceCreate($attributes + [
      'articul' => 'FS-' . mb_strtoupper(uniqid()),
      'shop_id' => $this->shop->id,
      'status_id' => 1,
      'unit_type_id' => 2,
      'effective_stock_mode' => 'normal',
    ]);

    if ($remain !== null) {
      Remain::withoutGlobalScopes()->forceCreate([
        'product_id' => $product->id,
        'shop_id' => $product->shop_id,
        'quantity' => $remain,
      ]);
    }

    return $product;
  }

  private function makePrice(Product $product, int $shopId): void
  {
    Price::withoutGlobalScopes()->forceCreate([
      'product_id' => $product->id,
      'shop_id' => $shopId,
      'type_id' => Type::PRICE_DEFAULT,
      'value' => 4000,
      'step' => 1,
    ]);
  }

  private function makeOrder(): int
  {
    $clientId = DB::table('clients')->insertGetId([
      'is_authorized' => 1,
      'newsletter_subscribed' => 0,
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    return DB::table('orders')->insertGetId([
      'shop_id' => $this->shop->id,
      'uuid' => 'fs-' . uniqid(),
      'payment_type_id' => 1,
      'type_id' => 2,
      'client_id' => $clientId,
      'status_id' => 2,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  /** Сборщик с открытой сменой — гейт @can(ability: "onShift") на product_* queries. */
  private function actingAsCollectorOnShift(): void
  {
    $userId = DB::table('users')->insertGetId([
      'name' => 'Сборщик',
      'surname' => 'Фикс-остатка ' . uniqid(),
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    DB::table('shifts')->insert([
      'user_id' => $userId,
      'shop_id' => $this->shop->id,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $this->actingAs(User::findOrFail($userId), 'api');
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
}
