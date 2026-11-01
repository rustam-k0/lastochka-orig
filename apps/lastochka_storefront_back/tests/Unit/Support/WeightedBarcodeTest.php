<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\WeightedBarcode;
use PHPUnit\Framework\TestCase;

/**
 * Чистый юнит-тест парсинга весового штрихкода «23»+код (без БД/Laravel).
 */
final class WeightedBarcodeTest extends TestCase
{
    public function test_has_embedded_code(): void
    {
        $this->assertTrue(WeightedBarcode::hasEmbeddedCode('2312345678901'));
        $this->assertTrue(WeightedBarcode::hasEmbeddedCode('2300296000000'));
        $this->assertFalse(WeightedBarcode::hasEmbeddedCode('4810099032723'));
        $this->assertFalse(WeightedBarcode::hasEmbeddedCode('23123'));          // не 13 цифр
        $this->assertFalse(WeightedBarcode::hasEmbeddedCode('2222222222'));     // 10 цифр — не весовой
        $this->assertFalse(WeightedBarcode::hasEmbeddedCode('23ABCDE678901'));  // не цифры
        $this->assertFalse(WeightedBarcode::hasEmbeddedCode(null));
    }

    public function test_all_prefixes_20_to_29_are_weighted(): void
    {
        foreach (range(20, 29) as $prefix) {
            $barcode = $prefix.'12345678901'; // EAN-13
            $this->assertTrue(WeightedBarcode::hasEmbeddedCode($barcode), "Префикс {$prefix}");
            $this->assertSame('12345', WeightedBarcode::extractCode($barcode));
        }
    }

    public function test_prefix_outside_20_29_is_not_weighted(): void
    {
        $this->assertFalse(WeightedBarcode::hasEmbeddedCode('1912345678901')); // 19
        $this->assertFalse(WeightedBarcode::hasEmbeddedCode('3012345678901')); // 30
    }

    public function test_extract_code_strips_leading_zeros(): void
    {
        $this->assertSame('12345', WeightedBarcode::extractCode('2312345678901'));
        $this->assertSame('296', WeightedBarcode::extractCode('2300296123456'));
        $this->assertSame('42', WeightedBarcode::extractCode('2300042123456'));
        $this->assertSame('0', WeightedBarcode::extractCode('2300000123456'));
        $this->assertNull(WeightedBarcode::extractCode('4810099032723'));
    }

    public function test_code_candidates_cover_all_paddings(): void
    {
        $this->assertSame(['296', '0296', '00296'], WeightedBarcode::codeCandidates('2300296123456'));
        $this->assertSame(['12345'], WeightedBarcode::codeCandidates('2312345678901'));
    }

    public function test_code_candidates_empty_for_non_weighted(): void
    {
        $this->assertSame([], WeightedBarcode::codeCandidates('4810099032723'));
        $this->assertSame([], WeightedBarcode::codeCandidates(null));
    }
}
