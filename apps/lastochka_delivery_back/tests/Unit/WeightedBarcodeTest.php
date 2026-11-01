<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\WeightedBarcode;
use PHPUnit\Framework\TestCase;

/**
 * Чистый юнит-тест парсинга весового штрихкода (префикс 20–29)+код.
 * Без БД/Laravel (голый PHPUnit) — безопасно для prod-БД мины delivery_back.
 */
final class WeightedBarcodeTest extends TestCase
{
    public function test_has_embedded_code_for_weighted_barcode(): void
    {
        $this->assertTrue(WeightedBarcode::hasEmbeddedCode('2312345678901'));
        $this->assertTrue(WeightedBarcode::hasEmbeddedCode('2300042000000')); // 13 цифр
    }

    public function test_all_prefixes_20_to_29_are_weighted(): void
    {
        foreach (range(20, 29) as $prefix) {
            $barcode = $prefix.'12345678901'; // префикс(2) + 11 цифр = EAN-13
            $this->assertTrue(
                WeightedBarcode::hasEmbeddedCode($barcode),
                "Префикс {$prefix} должен считаться весовым"
            );
            $this->assertSame('12345', WeightedBarcode::extractCode($barcode));
        }
    }

    public function test_prefix_outside_20_29_is_not_weighted(): void
    {
        $this->assertFalse(WeightedBarcode::hasEmbeddedCode('1912345678901')); // 19
        $this->assertFalse(WeightedBarcode::hasEmbeddedCode('3012345678901')); // 30
    }

    public function test_extract_code_takes_5_digits_after_prefix(): void
    {
        $this->assertSame('12345', WeightedBarcode::extractCode('2312345678901'));
    }

    public function test_extract_code_strips_leading_zeros(): void
    {
        // «00296» из штрихкода = код «296» в products.code (нули — padding).
        $this->assertSame('296', WeightedBarcode::extractCode('2300296123456'));
        $this->assertSame('42', WeightedBarcode::extractCode('2300042123456'));
    }

    public function test_extract_code_all_zeros_normalises_to_zero(): void
    {
        $this->assertSame('0', WeightedBarcode::extractCode('2300000123456'));
    }

    public function test_code_candidates_cover_all_paddings(): void
    {
        // Кандидаты матчат хранимые «296», «0296», «00296».
        $this->assertSame(['296', '0296', '00296'], WeightedBarcode::codeCandidates('2300296123456'));
        $this->assertSame(['12345'], WeightedBarcode::codeCandidates('2312345678901'));
    }

    public function test_code_candidates_empty_for_non_weighted(): void
    {
        $this->assertSame([], WeightedBarcode::codeCandidates('4810099032723'));
        $this->assertSame([], WeightedBarcode::codeCandidates(null));
    }

    public function test_non_weighted_barcode_has_no_embedded_code(): void
    {
        $this->assertFalse(WeightedBarcode::hasEmbeddedCode('4810099032723')); // обычный EAN
        $this->assertNull(WeightedBarcode::extractCode('4810099032723'));
    }

    public function test_other_internal_prefix_in_range_is_weighted(): void
    {
        // Внутренние префиксы 20–29 все весовые (касса/1С может использовать любой).
        $this->assertTrue(WeightedBarcode::hasEmbeddedCode('2212345678901'));
        $this->assertSame('12345', WeightedBarcode::extractCode('2212345678901'));
    }

    public function test_only_full_ean13_length_is_weighted(): void
    {
        // Валидный префикс 20–29, но длина не 13 — не весовой
        // (отсекает обычные короткие штрихкоды/коды вроде «2222222222»).
        $this->assertFalse(WeightedBarcode::hasEmbeddedCode('23123'));         // 5
        $this->assertFalse(WeightedBarcode::hasEmbeddedCode('2300042'));       // 7
        $this->assertFalse(WeightedBarcode::hasEmbeddedCode('2222222222'));    // 10
        $this->assertFalse(WeightedBarcode::hasEmbeddedCode('23123456789012')); // 14
        $this->assertNull(WeightedBarcode::extractCode('2300042'));
    }

    public function test_non_digit_code_segment_is_not_weighted(): void
    {
        $this->assertFalse(WeightedBarcode::hasEmbeddedCode('23ABCDE678901'));
    }

    public function test_null_barcode_is_safe(): void
    {
        $this->assertFalse(WeightedBarcode::hasEmbeddedCode(null));
        $this->assertNull(WeightedBarcode::extractCode(null));
    }
}
