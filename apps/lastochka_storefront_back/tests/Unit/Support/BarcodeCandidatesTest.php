<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Barcode;
use PHPUnit\Framework\TestCase;

/**
 * Кандидаты хранимого штрихкода для отсканированного значения — толерантность
 * к ведущим нулям в обе стороны. 1С отдаёт EAN-8/UPC-A дополненными нулями
 * до 13 знаков («0000080768258» для EAN-8 «80768258»), а сканер возвращает
 * штрихкод как есть. Чистый юнит без БД/Laravel.
 */
final class BarcodeCandidatesTest extends TestCase
{
    public function test_ean8_scanned_raw_matches_stored_padded_to_13(): void
    {
        $this->assertSame(
            ['80768258', '0000080768258'],
            Barcode::storedCandidates('80768258')
        );
    }

    public function test_upc_a_scanned_raw_matches_stored_padded_to_13(): void
    {
        $this->assertSame(
            ['873999000036', '0873999000036'],
            Barcode::storedCandidates('873999000036')
        );
    }

    public function test_scanned_padded_matches_stored_raw(): void
    {
        // Сканер/касса вернули EAN-8 уже дополненным — в базе может лежать сырой.
        $this->assertSame(
            ['0000080768258', '80768258'],
            Barcode::storedCandidates('0000080768258')
        );
    }

    public function test_full_ean13_has_single_candidate(): void
    {
        $this->assertSame(['4602248008341'], Barcode::storedCandidates('4602248008341'));
    }

    public function test_internal_prefix_21_ean13_has_single_candidate(): void
    {
        $this->assertSame(['2100100058357'], Barcode::storedCandidates('2100100058357'));
    }

    public function test_non_digit_input_is_returned_as_is(): void
    {
        $this->assertSame(['ABC-123'], Barcode::storedCandidates('ABC-123'));
        $this->assertSame([' 80768258 '], Barcode::storedCandidates(' 80768258 '));
    }

    public function test_all_zeros_and_empty(): void
    {
        $this->assertSame([], Barcode::storedCandidates(''));
        $this->assertSame(['0000000000000', '0'], Barcode::storedCandidates('0000000000000'));
    }
}
