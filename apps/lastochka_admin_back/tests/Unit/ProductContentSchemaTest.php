<?php

namespace Tests\Unit;

use App\Services\ProductContent\ProductContentSchema;
use PHPUnit\Framework\TestCase;

class ProductContentSchemaTest extends TestCase
{
  public function test_headers_contain_29_unique_columns(): void
  {
    $this->assertCount(29, ProductContentSchema::HEADERS);
    $this->assertSame(
      ProductContentSchema::HEADERS,
      array_values(array_unique(ProductContentSchema::HEADERS)),
    );
    $this->assertSame('id', ProductContentSchema::HEADERS[0]);
  }

  public function test_fillable_columns_are_subset_of_headers(): void
  {
    $fillable = array_merge(
      array_keys(ProductContentSchema::PRODUCT_FIELDS),
      array_keys(ProductContentSchema::ATTRIBUTE_COLUMNS),
      [ProductContentSchema::COL_PHOTO_URLS],
    );

    foreach ($fillable as $col) {
      $this->assertContains($col, ProductContentSchema::HEADERS, "«{$col}» нет в HEADERS");
    }
  }

  public function test_is_empty_value(): void
  {
    $this->assertTrue(ProductContentSchema::isEmptyValue(null));
    $this->assertTrue(ProductContentSchema::isEmptyValue(''));
    $this->assertTrue(ProductContentSchema::isEmptyValue('  '));
    $this->assertTrue(ProductContentSchema::isEmptyValue('0')); // заглушка БЖУ
    $this->assertFalse(ProductContentSchema::isEmptyValue('0,5'));
    $this->assertFalse(ProductContentSchema::isEmptyValue('72,5'));
    $this->assertFalse(ProductContentSchema::isEmptyValue('текст'));
  }

  public function test_split_multiline(): void
  {
    $this->assertSame([], ProductContentSchema::splitMultiline(null));
    $this->assertSame([], ProductContentSchema::splitMultiline("  \n \n"));
    $this->assertSame(
      ['https://a.ru/1.jpg', 'https://a.ru/2.jpg'],
      ProductContentSchema::splitMultiline("https://a.ru/1.jpg\n\n https://a.ru/2.jpg \n"),
    );
  }

  public function test_instruction_rows_cover_every_header(): void
  {
    $described = array_column(ProductContentSchema::instructionRows(), 0);
    foreach (ProductContentSchema::HEADERS as $header) {
      $this->assertContains($header, $described, "В инструкции нет колонки «{$header}»");
    }
  }
}
