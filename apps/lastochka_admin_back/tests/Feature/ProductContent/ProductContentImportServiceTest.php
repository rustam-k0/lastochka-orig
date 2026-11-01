<?php

namespace Tests\Feature\ProductContent;

use App\Events\ProductUpdated;
use App\Models\Attribute;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Status;
use App\Models\Type;
use App\Services\ProductContent\ProductContentImportService;
use App\Services\ProductContent\ProductContentSchema as Schema;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ProductContentImportServiceTest extends TestCase
{
  use DatabaseTransactions;

  private ProductContentImportService $service;

  protected function setUp(): void
  {
    parent::setUp();
    $this->service = app(ProductContentImportService::class);
    $this->ensureAttributes();
  }

  private function ensureAttributes(): void
  {
    foreach (Schema::ATTRIBUTE_COLUMNS as $code) {
      Attribute::query()->firstOrCreate(
        ['code' => $code],
        [
          'title' => $code,
          'type_id' => $code === 'composition' ? Type::ATTRIBUTE_TEXT : Type::ATTRIBUTE_STRING,
          'category_type_id' => Type::ATTRIBUTE_COMPOSITION,
          'position' => 0,
        ],
      );
    }
  }

  private function makeProduct(array $attrs = []): Product
  {
    return Product::query()->create(array_merge([
      'title' => 'Import Test ' . uniqid(),
      'status_id' => Status::CODE_ACTIVE,
    ], $attrs));
  }

  private function row(Product $product, array $overrides = []): array
  {
    return array_merge([Schema::COL_ID => (string) $product->id], $overrides);
  }

  private function attrValue(Product $product, string $code): ?string
  {
    $attribute = Attribute::query()->where('code', $code)->first();
    $pa = ProductAttribute::query()
      ->where('product_id', $product->id)
      ->where('attribute_id', $attribute->id)
      ->first();

    return $pa?->string_value ?? $pa?->text_value;
  }

  public function test_writes_empty_product_fields_and_attributes(): void
  {
    $product = $this->makeProduct();

    $result = $this->service->importRow($this->row($product, [
      Schema::COL_DESCRIPTION => 'Новое описание от парсера',
      Schema::COL_PROTEINS => '8,6',
      Schema::COL_COMPOSITION => 'мука, вода, соль',
    ]), rowNumber: 2);

    $this->assertSame([], $result->errors);
    $this->assertTrue($result->changed());

    $product->refresh();
    $this->assertSame('Новое описание от парсера', $product->description);
    $this->assertSame('8,6', $this->attrValue($product, 'proteins'));
    $this->assertSame('мука, вода, соль', $this->attrValue($product, 'composition'));

    // composition — TEXT-атрибут: значение обязано лежать в text_value
    $composition = Attribute::query()->where('code', 'composition')->first();
    $pa = ProductAttribute::query()
      ->where('product_id', $product->id)
      ->where('attribute_id', $composition->id)
      ->first();
    $this->assertNotNull($pa->text_value);
    $this->assertNull($pa->string_value);
  }

  public function test_does_not_overwrite_filled_without_flag_and_overwrites_with_flag(): void
  {
    $product = $this->makeProduct(['description' => 'Ручное описание']);

    $result = $this->service->importRow($this->row($product, [
      Schema::COL_DESCRIPTION => 'Парсерное описание',
    ]), rowNumber: 2);

    $this->assertContains(Schema::COL_DESCRIPTION, $result->skipped);
    $this->assertSame('Ручное описание', $product->refresh()->description);

    $result = $this->service->importRow($this->row($product, [
      Schema::COL_DESCRIPTION => 'Парсерное описание',
    ]), rowNumber: 2, overwrite: true);

    $this->assertContains(Schema::COL_DESCRIPTION, $result->updated);
    $this->assertSame('Парсерное описание', $product->refresh()->description);
  }

  public function test_stub_zero_in_db_treated_as_empty(): void
  {
    $product = $this->makeProduct();
    $attribute = Attribute::query()->where('code', 'calories')->first();
    ProductAttribute::query()->create([
      'product_id' => $product->id,
      'attribute_id' => $attribute->id,
      'string_value' => '0', // заглушка после синка 1С
    ]);

    $result = $this->service->importRow($this->row($product, [
      Schema::COL_CALORIES => '250',
    ]), rowNumber: 2);

    $this->assertContains(Schema::COL_CALORIES, $result->updated);
    $this->assertSame('250', $this->attrValue($product, 'calories'));
  }

  public function test_stub_zero_from_file_is_ignored(): void
  {
    Event::fake([ProductUpdated::class]);

    $product = $this->makeProduct();

    $result = $this->service->importRow($this->row($product, [
      Schema::COL_PROTEINS => '0',
    ]), rowNumber: 2);

    $this->assertSame([], $result->updated);
    $this->assertSame([], $result->errors);
    $this->assertFalse($result->changed());
    $this->assertNull($this->attrValue($product, 'proteins'));

    $attribute = Attribute::query()->where('code', 'proteins')->first();
    $this->assertSame(0, ProductAttribute::query()
      ->where('product_id', $product->id)
      ->where('attribute_id', $attribute->id)
      ->count(), 'Заглушка «0» из файла не должна создавать атрибут');

    Event::assertNotDispatched(ProductUpdated::class);
  }

  public function test_empty_cell_never_wipes_db_value_even_with_overwrite(): void
  {
    $product = $this->makeProduct(['description' => 'Не стирать']);

    $result = $this->service->importRow($this->row($product, [
      Schema::COL_DESCRIPTION => '',
    ]), rowNumber: 2, overwrite: true);

    $this->assertFalse($result->changed());
    $this->assertSame('Не стирать', $product->refresh()->description);
  }

  public function test_never_touches_activation_flags_and_ignores_reference_columns(): void
  {
    $product = $this->makeProduct([
      'status_id' => Status::CODE_NOT_PUBLISHED,
      'publish_after_unlock' => false,
      'articul' => 'ART-42',
    ]);

    $this->service->importRow($this->row($product, [
      Schema::COL_DESCRIPTION => 'Контент',
      // справочные колонки в файле — импорт обязан их игнорировать
      Schema::COL_STATUS => 'активен',
      Schema::COL_ARTICUL => 'HACKED',
      Schema::COL_TITLE => 'Новое название',
      Schema::COL_PRICE => '9999',
    ]), rowNumber: 2, overwrite: true);

    $product->refresh();
    $this->assertSame(Status::CODE_NOT_PUBLISHED, $product->status_id);
    $this->assertFalse((bool) $product->publish_after_unlock);
    $this->assertSame('ART-42', $product->articul);
    $this->assertStringStartsWith('Import Test', $product->title);
    $this->assertSame('Контент', $product->description);
  }

  public function test_validation_errors(): void
  {
    $product = $this->makeProduct();

    $result = $this->service->importRow($this->row($product, [
      Schema::COL_PROTEINS => 'много',            // не число
      Schema::COL_SHELF_LIFE => str_repeat('щ', 256), // длиннее 255
      Schema::COL_FATS => '8.6 г',                 // валидно: число + суффикс
    ]), rowNumber: 2);

    $this->assertArrayHasKey(Schema::COL_PROTEINS, $result->errors);
    $this->assertArrayHasKey(Schema::COL_SHELF_LIFE, $result->errors);
    $this->assertContains(Schema::COL_FATS, $result->updated);
    $this->assertNull($this->attrValue($product, 'proteins'));
    $this->assertSame('8.6 г', $this->attrValue($product, 'fats'));
  }

  public function test_unknown_and_trashed_product(): void
  {
    $missing = $this->service->importRow([Schema::COL_ID => '99999999'], rowNumber: 2);
    $this->assertArrayHasKey(Schema::COL_ID, $missing->errors);

    $bad = $this->service->importRow([Schema::COL_ID => 'abc'], rowNumber: 3);
    $this->assertArrayHasKey(Schema::COL_ID, $bad->errors);

    $trashed = $this->makeProduct();
    $trashed->delete();
    $gone = $this->service->importRow($this->row($trashed, [Schema::COL_DESCRIPTION => 'x']), rowNumber: 4);
    $this->assertArrayHasKey(Schema::COL_ID, $gone->errors);
  }

  public function test_dry_run_writes_nothing_but_reports(): void
  {
    $product = $this->makeProduct();

    $result = $this->service->importRow($this->row($product, [
      Schema::COL_DESCRIPTION => 'Dry run',
    ]), rowNumber: 2, dryRun: true);

    $this->assertContains(Schema::COL_DESCRIPTION, $result->updated);
    $this->assertNull($product->refresh()->description);
  }

  public function test_dispatches_product_updated_only_when_changed(): void
  {
    Event::fake([ProductUpdated::class]);

    $changed = $this->makeProduct();
    $untouched = $this->makeProduct(['description' => 'Занято']);

    $this->service->importRow($this->row($changed, [Schema::COL_DESCRIPTION => 'Новое']), rowNumber: 2);
    $this->service->importRow($this->row($untouched, [Schema::COL_DESCRIPTION => 'Мимо']), rowNumber: 3);

    Event::assertDispatchedTimes(ProductUpdated::class, 1);
    Event::assertDispatched(ProductUpdated::class, fn ($e) => $e->product->id === $changed->id);
  }

  public function test_photos_added_only_to_products_without_photos(): void
  {
    \Illuminate\Support\Facades\Http::fake([
      '*' => \Illuminate\Support\Facades\Http::response(
        base64_decode('/9j/4AAQSkZJRgABAQEAAAAAAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='),
        200,
        ['Content-Type' => 'image/jpeg'],
      ),
    ]);
    \Illuminate\Support\Facades\Storage::fake();

    $bare = $this->makeProduct();
    $withPhoto = $this->makeProduct();
    $withPhoto->saveImg(['path' => '/files/old.jpg', 'origin_name' => 'old.jpg'], Status::CODE_HEAD_IMG);

    $r1 = $this->service->importRow($this->row($bare, [
      Schema::COL_PHOTO_URLS => "https://cdn.example.com/1.jpg\nhttps://cdn.example.com/2.jpg",
    ]), rowNumber: 2);

    $r2 = $this->service->importRow($this->row($withPhoto, [
      Schema::COL_PHOTO_URLS => 'https://cdn.example.com/3.jpg',
    ]), rowNumber: 3);

    $this->assertContains(Schema::COL_PHOTO_URLS, $r1->updated);
    $this->assertSame(2, \App\Models\JoinImg::query()->where('item_id', $bare->id)->where('table_name', 'products')->count());

    $this->assertContains(Schema::COL_PHOTO_URLS, $r2->skipped);
    $this->assertSame(1, \App\Models\JoinImg::query()->where('item_id', $withPhoto->id)->where('table_name', 'products')->count());
  }

  public function test_dry_run_does_not_download_photos_but_validates_url_syntax(): void
  {
    \Illuminate\Support\Facades\Http::fake(); // любой реальный запрос был бы записан

    $product = $this->makeProduct();

    $ok = $this->service->importRow($this->row($product, [
      Schema::COL_PHOTO_URLS => 'https://cdn.example.com/1.jpg',
    ]), rowNumber: 2, dryRun: true);

    $bad = $this->service->importRow($this->row($product, [
      Schema::COL_PHOTO_URLS => 'ftp://cdn.example.com/1.jpg',
    ]), rowNumber: 3, dryRun: true);

    $this->assertContains(Schema::COL_PHOTO_URLS, $ok->updated);
    $this->assertArrayHasKey(Schema::COL_PHOTO_URLS, $bad->errors);
    \Illuminate\Support\Facades\Http::assertNothingSent();
    $this->assertSame(0, \App\Models\JoinImg::query()->where('item_id', $product->id)->count());
  }
}
