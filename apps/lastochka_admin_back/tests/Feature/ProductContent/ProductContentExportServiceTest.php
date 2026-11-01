<?php

namespace Tests\Feature\ProductContent;

use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Status;
use App\Models\Type;
use App\Services\ProductContent\ProductContentExportService;
use App\Services\ProductContent\ProductContentSchema;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProductContentExportServiceTest extends TestCase
{
  use DatabaseTransactions;

  private ProductContentExportService $service;

  protected function setUp(): void
  {
    parent::setUp();
    $this->service = new ProductContentExportService();
  }

  private function makeProduct(array $attrs = []): Product
  {
    return Product::query()->create(array_merge([
      'title' => 'Export Test ' . uniqid(),
      'status_id' => Status::CODE_ACTIVE,
    ], $attrs));
  }

  private function attribute(string $code, int $typeId = Type::ATTRIBUTE_STRING): Attribute
  {
    return Attribute::query()->firstOrCreate(
      ['code' => $code],
      [
        'title' => $code,
        'type_id' => $typeId,
        'category_type_id' => Type::ATTRIBUTE_COMPOSITION,
        'position' => 0,
      ],
    );
  }

  private function setAttr(Product $product, string $code, ?string $value, string $column = 'string_value'): void
  {
    ProductAttribute::query()->create([
      'product_id' => $product->id,
      'attribute_id' => $this->attribute($code, $column === 'text_value' ? Type::ATTRIBUTE_TEXT : Type::ATTRIBUTE_STRING)->id,
      $column => $value,
    ]);
  }

  /** Найти строку выгрузки по id товара. Локальная БД живая — ищем только свои строки. */
  private function findRow(int $productId, bool $onlyUnfilled = false): ?array
  {
    foreach ($this->service->rows($onlyUnfilled) as $row) {
      if ($row[0] === (string) $productId) {
        $assoc = [];
        foreach (ProductContentSchema::HEADERS as $i => $header) {
          $assoc[$header] = $row[$i];
        }
        return $assoc;
      }
    }
    return null;
  }

  public function test_exports_active_hidden_and_trashed_products(): void
  {
    $active = $this->makeProduct();
    $hidden = $this->makeProduct(['status_id' => Status::CODE_NOT_PUBLISHED]);
    $trashed = $this->makeProduct();
    $trashed->delete();

    $activeRow = $this->findRow($active->id);
    $hiddenRow = $this->findRow($hidden->id);
    $trashedRow = $this->findRow($trashed->id);

    $this->assertNotNull($activeRow);
    $this->assertSame('активен', $activeRow[ProductContentSchema::COL_STATUS]);
    $this->assertSame('', $activeRow[ProductContentSchema::COL_DELETED]);

    $this->assertNotNull($hiddenRow);
    $this->assertSame('скрыт', $hiddenRow[ProductContentSchema::COL_STATUS]);

    $this->assertNotNull($trashedRow);
    $this->assertSame('да', $trashedRow[ProductContentSchema::COL_DELETED]);
  }

  public function test_row_has_header_count_and_all_strings(): void
  {
    $product = $this->makeProduct();

    foreach ($this->service->rows() as $row) {
      if ($row[0] !== (string) $product->id) {
        continue;
      }
      $this->assertCount(count(ProductContentSchema::HEADERS), $row);
      foreach ($row as $value) {
        $this->assertIsString($value);
      }
      return;
    }
    $this->fail('Строка товара не найдена в выгрузке');
  }

  public function test_filled_values_exported_as_is_and_stub_zero_as_empty(): void
  {
    $product = $this->makeProduct(['description' => 'Уже есть описание']);
    $this->setAttr($product, 'proteins', '72,5');
    $this->setAttr($product, 'calories', '0'); // заглушка = не заполнено
    $this->setAttr($product, 'composition', 'мука, вода', 'text_value');

    $row = $this->findRow($product->id);

    $this->assertSame('Уже есть описание', $row[ProductContentSchema::COL_DESCRIPTION]);
    $this->assertSame('72,5', $row[ProductContentSchema::COL_PROTEINS]);
    $this->assertSame('', $row[ProductContentSchema::COL_CALORIES]);
    $this->assertSame('мука, вода', $row[ProductContentSchema::COL_COMPOSITION]);
    $this->assertSame('', $row[ProductContentSchema::COL_FATS]);
    $this->assertSame('', $row[ProductContentSchema::COL_PHOTO_URLS], 'Колонка фото-URL всегда пустая при экспорте');
  }

  public function test_barcodes_merged_multiline_unique(): void
  {
    $product = $this->makeProduct([
      'barcode' => '4600000000001',
      'barcodes' => ['4600000000001', '4600000000002'],
    ]);

    $row = $this->findRow($product->id);

    $this->assertSame("4600000000001\n4600000000002", $row[ProductContentSchema::COL_BARCODES]);
  }

  public function test_category_chain_and_unit_label(): void
  {
    // Если Category::create упадёт на NOT NULL другой колонки — добавить её
    // в массив с минимальным валидным значением (например 'status_id' => Status::CODE_ACTIVE).
    $root = Category::query()->create(['title' => 'Молочка ' . uniqid()]);
    $leaf = Category::query()->create(['title' => 'Сыры', 'parent_id' => $root->id]);

    $product = $this->makeProduct(['info_weight_type_id' => Type::INFO_WEIGHT_KILOGRAM]);
    $product->categories()->attach([$leaf->id => ['position' => 0]]);

    $row = $this->findRow($product->id);

    $this->assertSame($root->title . ' > Сыры', $row[ProductContentSchema::COL_CATEGORY]);
    $this->assertSame('кг', $row[ProductContentSchema::COL_UNIT]);
  }

  public function test_photo_count_and_price_and_stock(): void
  {
    $product = $this->makeProduct();
    $product->saveImg(['path' => '/files/test-head.jpg', 'origin_name' => 'h.jpg'], Status::CODE_HEAD_IMG);
    $product->saveImg(['path' => '/files/test-slide.jpg', 'origin_name' => 's.jpg'], Status::CODE_SLIDER_IMG);
    $product->prices()->create(['type_id' => Type::PRICE_DEFAULT, 'shop_id' => null, 'value' => 129.99, 'step' => 1]);
    $product->remains()->create(['shop_id' => null, 'quantity' => 7]);

    $row = $this->findRow($product->id);

    $this->assertSame('2', $row[ProductContentSchema::COL_PHOTO_COUNT]);
    $this->assertSame('129.99', $row[ProductContentSchema::COL_PRICE]);
    $this->assertSame('7 шт', $row[ProductContentSchema::COL_STOCK]);
  }

  public function test_weighted_stock_is_in_base_unit_with_fraction(): void
  {
    // Фасовка 300 г, остаток 12.806 кг: «12» рядом с «г» читалось как 12 граммов.
    $product = $this->makeProduct([
      'info_weight_type_id' => Type::INFO_WEIGHT_GRAM,
      'info_weight' => 300,
      'base_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM,
    ]);
    $product->remains()->create(['shop_id' => null, 'quantity' => 12.806]);

    $row = $this->findRow($product->id);

    $this->assertSame('г', $row[ProductContentSchema::COL_UNIT]);
    $this->assertSame('300', $row[ProductContentSchema::COL_WEIGHT]);
    $this->assertSame('12.806 кг', $row[ProductContentSchema::COL_STOCK]);
  }

  public function test_only_unfilled_skips_fully_filled_product(): void
  {
    // Полностью заполненный: описание + все 10 атрибутов + фото
    $filled = $this->makeProduct(['description' => 'Есть']);
    foreach (ProductContentSchema::ATTRIBUTE_COLUMNS as $code) {
      $this->setAttr($filled, $code, 'значение', $code === 'composition' ? 'text_value' : 'string_value');
    }
    $filled->saveImg(['path' => '/files/f.jpg', 'origin_name' => 'f.jpg'], Status::CODE_HEAD_IMG);

    // Частично заполненный
    $partial = $this->makeProduct(['description' => 'Есть описание, но нет БЖУ и фото']);

    $this->assertNull($this->findRow($filled->id, onlyUnfilled: true));
    $this->assertNotNull($this->findRow($partial->id, onlyUnfilled: true));
    $this->assertNotNull($this->findRow($filled->id), 'Без фильтра заполненный товар присутствует');
  }
}
