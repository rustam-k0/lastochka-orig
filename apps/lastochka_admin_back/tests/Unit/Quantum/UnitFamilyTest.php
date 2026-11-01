<?php

namespace Tests\Unit\Quantum;

use App\Models\Type;
use App\Services\Quantum\UnitFamily;
use PHPUnit\Framework\TestCase;

class UnitFamilyTest extends TestCase
{
    public function test_family_resolution(): void
    {
        $this->assertSame('mass', UnitFamily::family(Type::INFO_WEIGHT_GRAM));
        $this->assertSame('mass', UnitFamily::family(Type::INFO_WEIGHT_KILOGRAM));
        $this->assertSame('volume', UnitFamily::family(Type::INFO_WEIGHT_LITER));
        $this->assertSame('volume', UnitFamily::family(Type::INFO_WEIGHT_M_LITER));
        $this->assertSame('piece', UnitFamily::family(Type::INFO_WEIGHT_PIECE));
        $this->assertNull(UnitFamily::family(null));
        $this->assertNull(UnitFamily::family(999));
    }

    public function test_smallest_unit_of_family(): void
    {
        $this->assertSame(Type::INFO_WEIGHT_GRAM, UnitFamily::smallestUnit('mass'));
        $this->assertSame(Type::INFO_WEIGHT_M_LITER, UnitFamily::smallestUnit('volume'));
        $this->assertSame(Type::INFO_WEIGHT_PIECE, UnitFamily::smallestUnit('piece'));
    }

    public function test_size_in_smallest(): void
    {
        $this->assertSame(1, UnitFamily::sizeInSmallest(Type::INFO_WEIGHT_GRAM));
        $this->assertSame(1000, UnitFamily::sizeInSmallest(Type::INFO_WEIGHT_KILOGRAM));
        $this->assertSame(1, UnitFamily::sizeInSmallest(Type::INFO_WEIGHT_M_LITER));
        $this->assertSame(1000, UnitFamily::sizeInSmallest(Type::INFO_WEIGHT_LITER));
        $this->assertSame(1, UnitFamily::sizeInSmallest(Type::INFO_WEIGHT_PIECE));
        $this->assertSame(1, UnitFamily::sizeInSmallest(null));
    }

    public function test_allowed_quantum_units(): void
    {
        // Объём (мл) пока не выдаётся — продажа по объёму не поддержана в purchase_back.
        $this->assertSame(
            [Type::INFO_WEIGHT_GRAM, Type::INFO_WEIGHT_PIECE],
            UnitFamily::allowedQuantumUnits()
        );
    }

    public function test_same_family(): void
    {
        $this->assertTrue(UnitFamily::sameFamily(Type::INFO_WEIGHT_GRAM, Type::INFO_WEIGHT_KILOGRAM));
        $this->assertTrue(UnitFamily::sameFamily(Type::INFO_WEIGHT_LITER, Type::INFO_WEIGHT_M_LITER));
        $this->assertFalse(UnitFamily::sameFamily(Type::INFO_WEIGHT_GRAM, Type::INFO_WEIGHT_PIECE));
        $this->assertFalse(UnitFamily::sameFamily(null, Type::INFO_WEIGHT_GRAM));
    }
}
