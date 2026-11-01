<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ProductCode;
use PHPUnit\Framework\TestCase;

/**
 * Варианты кода/PLU, толерантные к ведущим нулям. На ценнике 1С печатает PLU
 * весового товара дополненным нулями до 5 знаков («PLU: 00030»), а в базе код
 * хранится без них («30») — и наоборот, PLU может прийти уже дополненным.
 * Чистый юнит без БД/Laravel.
 */
final class ProductCodeCandidatesTest extends TestCase
{
    public function test_zero_padded_plu_from_price_tag_matches_stored_short_code(): void
    {
        $this->assertSame(['30', '030', '0030', '00030'], ProductCode::zeroPaddedCandidates('00030'));
    }

    public function test_short_input_matches_stored_padded_plu(): void
    {
        $this->assertSame(['30', '030', '0030', '00030'], ProductCode::zeroPaddedCandidates('30'));
    }

    public function test_code_longer_than_plu_has_single_candidate(): void
    {
        $this->assertSame(['52026'], ProductCode::zeroPaddedCandidates("52\u{A0}026"));
        $this->assertSame(['156024'], ProductCode::zeroPaddedCandidates('156024'));
    }

    public function test_long_input_with_leading_zeros_keeps_its_own_length(): void
    {
        $this->assertSame(
            ['30', '030', '0030', '00030', '000030'],
            ProductCode::zeroPaddedCandidates('000030')
        );
    }

    public function test_all_zeros(): void
    {
        $this->assertSame(['0', '00', '000', '0000', '00000'], ProductCode::zeroPaddedCandidates('000'));
    }

    public function test_non_digit_and_empty_input_has_no_candidates(): void
    {
        $this->assertSame([], ProductCode::zeroPaddedCandidates('FS-ABC'));
        $this->assertSame([], ProductCode::zeroPaddedCandidates(''));
        $this->assertSame([], ProductCode::zeroPaddedCandidates(null));
    }
}
