<?php

namespace Tests\Feature;

use App\GraphQL\Queries\Product\ProductSearchByShop;
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
 * Поиск товара сборщиком по штрихкоду: точное совпадение с сохранённым
 * штрихкодом важнее разбора «весового» штрихкода.
 *
 * 1С генерирует для собственной номенклатуры (выпечка/супы «Ласточка», торты,
 * игрушки) СТАТИЧНЫЕ внутренние EAN-13 с префиксом 20–29 (напр. 2100100058357 —
 * префикс 21 + сквозной счётчик, НЕ код товара). Такой штрихкод лежит в
 * products.barcode и должен находиться по точному совпадению. Раньше он
 * перехватывался как весовой (код «00100» → products.code → «не найден»).
 *
 * Весовой фолбэк (префикс + 5 цифр кода 1С + вес) остаётся, но только если
 * точного совпадения по штрихкоду нет.
 */
class ProductSearchBarcodeExactMatchTest extends TestCase
{
  use DatabaseTransactions, MakesGraphQLRequests;

  /** Статичный внутренний штрихкод 1С (Суп Чечевичный Ласточка, код 1С «171 289»). */
  private const INTERNAL_BARCODE = '2100100058357';

  /** Динамический штрихкод с весов: 23 + код 00023 + вес 01234 + к.ц. */
  private const WEIGHTED_BARCODE = '2300023012345';

  private Shop $shop;
  private Product $internalProduct;
  private Product $weightedProduct;

  protected function setUp(): void
  {
    parent::setUp();

    // Легаси-режим: товар «живёт» в своём магазине (products.shop_id = магазин заказа).
    $this->setNetworkCatalog(false);

    $this->shop = Shop::forceCreate(['title' => 'Barcode shop', 'code' => 'bc-shop-' . uniqid()]);

    // Штучный товар с внутренним EAN-13 от 1С; код 1С не имеет отношения к цифрам штрихкода.
    $this->internalProduct = $this->makeProductInStock([
      'title' => 'Суп Чечевичный Ласточка',
      'code' => '171 289',
      'barcode' => self::INTERNAL_BARCODE,
      'barcodes' => json_encode([self::INTERNAL_BARCODE]),
      'unit_type_id' => 2,
    ]);

    // Весовой товар: в 1С «штрихкод» = код, дополненный нулями до 13 знаков.
    $this->weightedProduct = $this->makeProductInStock([
      'title' => 'Огурцы Осетия',
      'code' => '23',
      'barcode' => '0000000000023',
      'barcodes' => json_encode(['0000000000023']),
      'unit_type_id' => 1,
      'info_weight_type_id' => Type::INFO_WEIGHT_GRAM,
    ]);
  }

  protected function tearDown(): void
  {
    Cache::forget(NetworkCatalogMode::CACHE_KEY);
    parent::tearDown();
  }

  // ---------------------------------------------------------------------
  // product_search_by_shop (резолвер ProductSearchByShop)
  // ---------------------------------------------------------------------

  public function test_search_by_shop_finds_product_by_stored_internal_barcode(): void
  {
    $found = (new ProductSearchByShop())(null, [
      'order_id' => $this->makeOrder(),
      'barcode' => self::INTERNAL_BARCODE,
    ]);

    $this->assertNotNull($found, 'внутренний EAN-13 из 1С должен находиться по точному совпадению barcode');
    $this->assertSame($this->internalProduct->id, $found->id);
  }

  public function test_search_by_shop_prefers_exact_barcode_over_embedded_code(): void
  {
    // Конкурент: товар с кодом «100» — ровно то, что WeightedBarcode вырезает из 2100100058357.
    $decoy = $this->makeProductInStock([
      'title' => 'Товар с кодом 100',
      'code' => '100',
      'barcode' => '4600000000100',
      'unit_type_id' => 2,
    ]);

    $found = (new ProductSearchByShop())(null, [
      'order_id' => $this->makeOrder(),
      'barcode' => self::INTERNAL_BARCODE,
    ]);

    $this->assertNotNull($found);
    $this->assertSame($this->internalProduct->id, $found->id, 'точное совпадение штрихкода приоритетнее разбора кода');
    $this->assertNotSame($decoy->id, $found->id);
  }

  public function test_search_by_shop_falls_back_to_embedded_code_for_weighted_barcode(): void
  {
    $found = (new ProductSearchByShop())(null, [
      'order_id' => $this->makeOrder(),
      'barcode' => self::WEIGHTED_BARCODE,
    ]);

    $this->assertNotNull($found, 'весовой штрихкод без точного совпадения ищется по коду 1С');
    $this->assertSame($this->weightedProduct->id, $found->id);
  }

  public function test_search_by_shop_returns_null_for_unknown_internal_barcode(): void
  {
    $found = (new ProductSearchByShop())(null, [
      'order_id' => $this->makeOrder(),
      'barcode' => '2100100099999', // нет ни такого штрихкода, ни товара с кодом «100»
    ]);

    $this->assertNull($found);
  }

  // ---------------------------------------------------------------------
  // ProductFilter::barcode (product_search / product_global_search)
  // ---------------------------------------------------------------------

  public function test_filter_finds_product_by_stored_internal_barcode(): void
  {
    $found = Product::query()
      ->whereKey([$this->internalProduct->id, $this->weightedProduct->id])
      ->filter(['barcode' => self::INTERNAL_BARCODE])
      ->first();

    $this->assertNotNull($found, 'фильтр barcode: внутренний EAN-13 из 1С находится по точному совпадению');
    $this->assertSame($this->internalProduct->id, $found->id);
  }

  public function test_filter_prefers_exact_barcode_over_embedded_code(): void
  {
    $decoy = $this->makeProductInStock([
      'title' => 'Товар с кодом 100',
      'code' => '100',
      'barcode' => '4600000000100',
      'unit_type_id' => 2,
    ]);

    // Оба кандидата в выборке; выигрывать должно точное совпадение штрихкода.
    $found = Product::query()
      ->whereKey([$decoy->id, $this->internalProduct->id])
      ->filter(['barcode' => self::INTERNAL_BARCODE])
      ->first();

    $this->assertNotNull($found);
    $this->assertSame($this->internalProduct->id, $found->id, 'фильтр barcode: точное совпадение приоритетнее кода');
  }

  public function test_filter_falls_back_to_embedded_code_for_weighted_barcode(): void
  {
    $found = Product::query()
      ->whereKey([$this->internalProduct->id, $this->weightedProduct->id])
      ->filter(['barcode' => self::WEIGHTED_BARCODE])
      ->first();

    $this->assertNotNull($found, 'фильтр barcode: весовой штрихкод ищется по коду 1С');
    $this->assertSame($this->weightedProduct->id, $found->id);
  }

  public function test_product_global_search_graphql_finds_internal_barcode(): void
  {
    $this->actingAsCollectorOnShift();

    $response = $this->graphQL(sprintf(
      '{ product_global_search(barcode: "%s") { id title } }',
      self::INTERNAL_BARCODE
    ));

    $response->assertJson([
      'data' => [
        'product_global_search' => [
          'id' => $this->internalProduct->id,
          'title' => 'Суп Чечевичный Ласточка',
        ],
      ],
    ]);
  }

  // ---------------------------------------------------------------------
  // EAN-8 / UPC-A: 1С отдаёт штрихкод дополненным нулями до 13 знаков,
  // сканер возвращает как есть («80768258») — поиск толерантен к ведущим нулям.
  // ---------------------------------------------------------------------

  public function test_search_by_shop_finds_ean8_stored_zero_padded_by_1c(): void
  {
    $kinder = $this->makeProductInStock([
      'title' => 'Киндер Сюрприз Джой 20г для девочек',
      'code' => '5 480',
      'barcode' => '0000080768258',
      'barcodes' => json_encode(['0000080768258']),
      'unit_type_id' => 2,
    ]);

    $found = (new ProductSearchByShop())(null, [
      'order_id' => $this->makeOrder(),
      'barcode' => '80768258',
    ]);

    $this->assertNotNull($found, 'EAN-8 со сканера должен находить товар, у которого 1С сохранила «0000080768258»');
    $this->assertSame($kinder->id, $found->id);
  }

  public function test_search_by_shop_finds_stored_raw_ean8_when_scanner_pads(): void
  {
    $raw = $this->makeProductInStock([
      'title' => 'Ручной товар с сырым EAN-8',
      'code' => '9 001',
      'barcode' => '80768258',
      'barcodes' => json_encode(['80768258']),
      'unit_type_id' => 2,
    ]);

    $found = (new ProductSearchByShop())(null, [
      'order_id' => $this->makeOrder(),
      'barcode' => '0000080768258',
    ]);

    $this->assertNotNull($found, 'дополненный нулями скан должен находить сырой EAN-8 в базе');
    $this->assertSame($raw->id, $found->id);
  }

  public function test_filter_finds_ean8_stored_zero_padded_by_1c(): void
  {
    $kinder = $this->makeProductInStock([
      'title' => 'Киндер Сюрприз Джой 20г для девочек',
      'code' => '5 480',
      'barcode' => '0000080768258',
      'unit_type_id' => 2,
    ]);

    $found = Product::query()
      ->whereKey([$kinder->id, $this->internalProduct->id])
      ->filter(['barcode' => '80768258'])
      ->first();

    $this->assertNotNull($found, 'фильтр barcode: EAN-8 со сканера находит «0000080768258»');
    $this->assertSame($kinder->id, $found->id);
  }

  public function test_filter_finds_upc_a_stored_zero_padded_by_1c(): void
  {
    $battery = $this->makeProductInStock([
      'title' => 'Батарейки Камелион Aa lr6 2шт',
      'code' => '96 987',
      'barcode' => '0873999000036',
      'unit_type_id' => 2,
    ]);

    $found = Product::query()
      ->whereKey([$battery->id, $this->internalProduct->id])
      ->filter(['barcode' => '873999000036'])
      ->first();

    $this->assertNotNull($found, 'фильтр barcode: UPC-A (12 цифр) со сканера находит «0873999000036»');
    $this->assertSame($battery->id, $found->id);
  }


  // ---------------------------------------------------------------------
  // Несколько штрихкодов у одного товара: 1С присылает список, admin_back
  // кладёт первый в products.barcode, весь список — в JSON products.barcodes.
  // Сканируется любой из них, а не только первый (на проде 2143 товара
  // с несколькими штрихкодами: разные партии, поставщики, фасовки).
  // ---------------------------------------------------------------------

  public function test_search_by_shop_finds_product_by_secondary_barcode(): void
  {
    $product = $this->makeProductInStock([
      'title' => 'Чуч-хела Тутти-фрутти 80г вишня',
      'code' => '157 400',
      'barcode' => '4612914356331',
      'barcodes' => json_encode(['4612914356331', '4612914360345']),
      'unit_type_id' => 2,
    ]);

    $found = (new ProductSearchByShop())(null, [
      'order_id' => $this->makeOrder(),
      'barcode' => '4612914360345',
    ]);

    $this->assertNotNull($found, 'второй штрихкод товара должен находить тот же товар');
    $this->assertSame($product->id, $found->id);
  }

  public function test_search_by_shop_finds_product_by_any_of_four_barcodes(): void
  {
    $product = $this->makeProductInStock([
      'title' => 'Носки Женские Мастер Хлопка',
      'code' => '162 001',
      'barcode' => '6973502774332',
      'barcodes' => json_encode(['6973502774332', '6973502774479', '6973502774660', '8695264489986']),
      'unit_type_id' => 2,
    ]);

    $orderId = $this->makeOrder();

    foreach (['6973502774332', '6973502774479', '6973502774660', '8695264489986'] as $barcode) {
      $found = (new ProductSearchByShop())(null, ['order_id' => $orderId, 'barcode' => $barcode]);

      $this->assertNotNull($found, "штрихкод $barcode из списка товара должен находить товар");
      $this->assertSame($product->id, $found->id, "штрихкод $barcode нашёл не тот товар");
    }
  }

  public function test_filter_finds_product_by_secondary_barcode(): void
  {
    $product = $this->makeProductInStock([
      'title' => 'Маслины Лукория 280мл без косточки',
      'code' => '158 100',
      'barcode' => '4689362228572',
      'barcodes' => json_encode(['4689362228572', '7930050060590']),
      'unit_type_id' => 2,
    ]);

    $found = Product::query()
      ->whereKey([$product->id, $this->internalProduct->id])
      ->filter(['barcode' => '7930050060590'])
      ->first();

    $this->assertNotNull($found, 'фильтр barcode: второй штрихкод находит товар');
    $this->assertSame($product->id, $found->id);
  }

  public function test_product_global_search_graphql_finds_secondary_barcode(): void
  {
    $product = $this->makeProductInStock([
      'title' => 'Корнишоны Лукория 1415мл мини маринованные',
      'code' => '158 200',
      'barcode' => '4689362228299',
      'barcodes' => json_encode(['4689362228299', '7930050060606']),
      'unit_type_id' => 2,
    ]);

    $this->actingAsCollectorOnShift();

    $response = $this->graphQL('{ product_global_search(barcode: "7930050060606") { id title } }');

    $response->assertJson([
      'data' => [
        'product_global_search' => [
          'id' => $product->id,
          'title' => 'Корнишоны Лукория 1415мл мини маринованные',
        ],
      ],
    ]);
  }

  public function test_search_by_shop_finds_secondary_ean8_stored_zero_padded_by_1c(): void
  {
    $product = $this->makeProductInStock([
      'title' => 'Товар с дополнительным EAN-8',
      'code' => '5 481',
      'barcode' => '4600000000017',
      'barcodes' => json_encode(['4600000000017', '0000080768258']),
      'unit_type_id' => 2,
    ]);

    $found = (new ProductSearchByShop())(null, [
      'order_id' => $this->makeOrder(),
      'barcode' => '80768258',
    ]);

    $this->assertNotNull($found, 'EAN-8 со сканера должен находить дополненный нулями штрихкод из списка');
    $this->assertSame($product->id, $found->id);
  }

  public function test_secondary_internal_barcode_wins_over_embedded_code(): void
  {
    // Внутренний EAN-13 1С (21 + сквозной счётчик) лежит ВТОРЫМ в списке —
    // разбирать его как весовой («00100» → код 1С «100») нельзя.
    $product = $this->makeProductInStock([
      'title' => 'Суп Харчо Ласточка',
      'code' => '171 290',
      'barcode' => '4600000000024',
      'barcodes' => json_encode(['4600000000024', '2100100077771']),
      'unit_type_id' => 2,
    ]);

    $decoy = $this->makeProductInStock([
      'title' => 'Товар с кодом 100 (конкурент)',
      'code' => '100',
      'barcode' => '4600000000101',
      'unit_type_id' => 2,
    ]);

    $found = (new ProductSearchByShop())(null, [
      'order_id' => $this->makeOrder(),
      'barcode' => '2100100077771',
    ]);

    $this->assertNotNull($found);
    $this->assertSame($product->id, $found->id, 'точное совпадение по списку штрихкодов приоритетнее разбора кода');
    $this->assertNotSame($decoy->id, $found->id);
  }

  // ---------------------------------------------------------------------
  // Код 1С у весового и штучного товара совпадает: у весовых 1С кладёт в «Код»
  // PLU весов (23, 24), у собственного производства «Ласточка» коды тоже
  // короткие. На проде 32 таких пары в одном магазине: этикетка «Огурцы
  // Кисловодские» (код 24) находила «Люля Говяжьи На Мангале» (код 24).
  // Этикетку с весом печатают только на весовой товар, поэтому штучный с тем
  // же кодом в выдачу не попадает, даже когда весового нет в наличии.
  // ---------------------------------------------------------------------

  /** Этикетка весов: префикс 22 + код 00024 + вес 00500 + к.ц. */
  private const CUCUMBER_LABEL = '2200024005000';

  public function test_search_by_shop_weighted_barcode_prefers_weighted_product_over_piece_with_same_code(): void
  {
    [$piece, $weighted] = $this->makePieceAndWeightedWithSameCode('24');

    $found = (new ProductSearchByShop())(null, [
      'order_id' => $this->makeOrder(),
      'barcode' => self::CUCUMBER_LABEL,
    ]);

    $this->assertNotNull($found);
    $this->assertSame($weighted->id, $found->id, 'этикетка с весом означает весовой товар, а не штучный с тем же кодом');
    $this->assertNotSame($piece->id, $found->id);
  }

  public function test_filter_weighted_barcode_prefers_weighted_product_over_piece_with_same_code(): void
  {
    [$piece, $weighted] = $this->makePieceAndWeightedWithSameCode('24');

    $found = Product::query()
      ->whereKey([$piece->id, $weighted->id])
      ->filter(['barcode' => self::CUCUMBER_LABEL])
      ->first();

    $this->assertNotNull($found);
    $this->assertSame($weighted->id, $found->id, 'фильтр barcode: весовой товар приоритетнее штучного с тем же кодом');
  }

  public function test_product_global_search_graphql_weighted_barcode_prefers_weighted_product(): void
  {
    [, $weighted] = $this->makePieceAndWeightedWithSameCode('24');
    $this->actingAsCollectorOnShift();

    $response = $this->graphQL(sprintf(
      '{ product_global_search(barcode: "%s") { id title } }',
      self::CUCUMBER_LABEL
    ));

    $response->assertJson([
      'data' => [
        'product_global_search' => [
          'id' => $weighted->id,
          'title' => 'Огурцы Кисловодские',
        ],
      ],
    ]);
  }

  public function test_search_by_shop_weighted_barcode_skips_piece_product_when_weighted_with_same_code_is_out_of_stock(): void
  {
    [, $weighted] = $this->makePieceAndWeightedWithSameCode('24');
    Remain::withoutGlobalScopes()->where('product_id', $weighted->id)->update(['quantity' => 0]);

    $found = (new ProductSearchByShop())(null, [
      'order_id' => $this->makeOrder(),
      'barcode' => self::CUCUMBER_LABEL,
    ]);

    $this->assertNull($found, 'весовой без остатка → «не найден», а не штучный товар с тем же кодом');
  }

  public function test_product_global_search_graphql_skips_piece_product_when_weighted_with_same_code_is_out_of_stock(): void
  {
    [, $weighted] = $this->makePieceAndWeightedWithSameCode('24');
    Remain::withoutGlobalScopes()->where('product_id', $weighted->id)->update(['quantity' => 0]);
    $this->actingAsCollectorOnShift();

    $response = $this->graphQL(sprintf(
      '{ product_global_search(barcode: "%s") { id title } }',
      self::CUCUMBER_LABEL
    ));

    $response->assertJson(['data' => ['product_global_search' => null]]);
  }

  public function test_weighted_barcode_still_finds_piece_product_when_code_is_not_shared(): void
  {
    $piece = $this->makeProductInStock([
      'title' => 'Борщ Красный Овощной Постный Ласточка',
      'code' => '25',
      'barcode' => '2100100065777',
      'unit_type_id' => 2,
      'info_weight_type_id' => Type::INFO_WEIGHT_PIECE,
    ]);

    $found = (new ProductSearchByShop())(null, [
      'order_id' => $this->makeOrder(),
      'barcode' => '2200025001000',
    ]);

    $this->assertNotNull($found, 'штучный отсекается, только если тот же код есть у весового товара');
    $this->assertSame($piece->id, $found->id);
  }

  // ---------------------------------------------------------------------
  // helpers
  // ---------------------------------------------------------------------

  /**
   * Штучный и весовой товар с одним кодом 1С. Штучный создаётся первым: без
   * явного приоритета MySQL отдаёт строку с меньшим id — ровно так на проде
   * «Люля» (id 13202) выигрывала у «Огурцов Осетия» (id 13309).
   *
   * @return array{0: Product, 1: Product}
   */
  private function makePieceAndWeightedWithSameCode(string $code): array
  {
    $piece = $this->makeProductInStock([
      'title' => 'Люля Говяжьи На Мангале',
      'code' => $code,
      'barcode' => '2100100065621',
      'barcodes' => json_encode(['2100100065621']),
      'unit_type_id' => 2,
      'info_weight_type_id' => Type::INFO_WEIGHT_PIECE,
    ]);

    $weighted = $this->makeProductInStock([
      'title' => 'Огурцы Кисловодские',
      'code' => $code,
      'barcode' => str_pad($code, 13, '0', STR_PAD_LEFT),
      'barcodes' => json_encode([str_pad($code, 13, '0', STR_PAD_LEFT)]),
      'unit_type_id' => 1,
      'info_weight_type_id' => Type::INFO_WEIGHT_GRAM,
    ]);

    return [$piece, $weighted];
  }

  private function makeProductInStock(array $attributes): Product
  {
    $product = Product::forceCreate($attributes + [
      'articul' => 'BC-' . mb_strtoupper(uniqid()),
      'shop_id' => $this->shop->id,
      'status_id' => 1,
      'effective_stock_mode' => 'normal',
    ]);

    Remain::withoutGlobalScopes()->forceCreate([
      'product_id' => $product->id,
      'shop_id' => $this->shop->id,
      'quantity' => 10,
    ]);

    return $product;
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
      'uuid' => 'bc-' . uniqid(),
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
    // User в этом репо guarded — сырой insert (паттерн PrinterQueryTest).
    $userId = DB::table('users')->insertGetId([
      'name' => 'Сборщик',
      'surname' => 'Штрихкодов ' . uniqid(),
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
