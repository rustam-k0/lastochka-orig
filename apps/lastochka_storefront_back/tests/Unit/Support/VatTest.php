<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Vat;
use PHPUnit\Framework\TestCase;

final class VatTest extends TestCase
{
    public function test_rate_from_code_maps_yookassa_codes(): void
    {
        $this->assertSame(0, Vat::rateFromCode(1));  // без НДС
        $this->assertSame(0, Vat::rateFromCode(2));  // 0%
        $this->assertSame(10, Vat::rateFromCode(3)); // 10%
        $this->assertSame(20, Vat::rateFromCode(4)); // 20%
        $this->assertSame(10, Vat::rateFromCode(5)); // 10/110
        $this->assertSame(20, Vat::rateFromCode(6)); // 20/120
        $this->assertSame(0, Vat::rateFromCode(99)); // неизвестный код
    }

    public function test_included_vat_is_extracted_from_gross(): void
    {
        // 120 ₽ с НДС 20% «в том числе» → 120 × 20/120 = 20 ₽
        $this->assertSame(20.0, Vat::includedVat(120.0, 20));
        // 110 ₽ с НДС 10% → 10 ₽
        $this->assertSame(10.0, Vat::includedVat(110.0, 10));
        // ставка 0 → 0
        $this->assertSame(0.0, Vat::includedVat(500.0, 0));
        // округление до копеек
        $this->assertSame(16.67, Vat::includedVat(100.0, 20));
    }
}
