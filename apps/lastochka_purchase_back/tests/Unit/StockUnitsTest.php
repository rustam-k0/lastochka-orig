<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Support\StockUnits;
use PHPUnit\Framework\TestCase;

/**
 * Перевод количеств сборки в единицу остатка (remains.quantity) и обратно.
 *
 * Остаток курьерки хранится в базовой единице 1С (base_unit_type_id): кг, л
 * или шт. Позиция заказа — в порциях (info_weight × info_weight_type_id),
 * фактический вес — в граммах/мл. Эталон — данные прода 2026-10-14:
 *  A: id 885, база кг, порция 300 г, остаток 12.806;
 *  B: id 1391, база кг, порция 1 кг (info_weight NULL), остаток 26.555;
 *  C: id 1, штучный, остаток 3.
 */
final class StockUnitsTest extends TestCase
{
    private function product(?int $baseUnit, ?int $infoWeightTypeId, ?int $infoWeight = null): Product
    {
        return (new Product())->forceFill([
            'base_unit_type_id'   => $baseUnit,
            'info_weight_type_id' => $infoWeightTypeId,
            'info_weight'         => $infoWeight,
        ]);
    }

    private function classA(): Product
    {
        return $this->product(3, 2, 300);
    }

    private function classB(): Product
    {
        return $this->product(3, 3, null);
    }

    private function classC(): Product
    {
        return $this->product(1, 1, null);
    }

    public function test_portions_convert_to_stock_unit(): void
    {
        $this->assertSame(0.9, StockUnits::portionsToStock($this->classA(), 3));
        $this->assertSame(2.0, StockUnits::portionsToStock($this->classB(), 2));
        $this->assertSame(2.0, StockUnits::portionsToStock($this->classC(), 2));
    }

    public function test_actual_weight_converts_to_stock_unit(): void
    {
        $this->assertSame(0.528, StockUnits::collectedToStock($this->classA(), 528, 2));
        $this->assertSame(0.846, StockUnits::collectedToStock($this->classB(), 846, 1));
    }

    public function test_without_actual_weight_collected_is_portions(): void
    {
        $this->assertSame(0.9, StockUnits::collectedToStock($this->classA(), null, 3));
        $this->assertSame(3.0, StockUnits::collectedToStock($this->classC(), null, 3));
    }

    public function test_stuck_actual_weight_on_piece_product_is_ignored(): void
    {
        // MID-246: у штучного товара мог залипнуть actual_weight — списываем штуки.
        $this->assertSame(1.0, StockUnits::collectedToStock($this->classC(), 200, 1));
    }

    public function test_stock_converts_to_portion_unit(): void
    {
        $this->assertSame(12806.0, StockUnits::stockToPortionUnit($this->classA(), 12.806));
        $this->assertSame(26.555, StockUnits::stockToPortionUnit($this->classB(), 26.555));
        $this->assertSame(3.0, StockUnits::stockToPortionUnit($this->classC(), 3.0));
    }

    public function test_volume_product_uses_liters_for_stock(): void
    {
        // Порция 500 мл, база литр: 2 порции = 1 л, 750 мл факта = 0.75 л.
        $juice = $this->product(4, 5, 500);

        $this->assertSame(1.0, StockUnits::portionsToStock($juice, 2));
        $this->assertSame(0.75, StockUnits::collectedToStock($juice, 750, 2));
        $this->assertSame(12500.0, StockUnits::stockToPortionUnit($juice, 12.5));
    }

    public function test_piece_quantum_counts_pieces_in_portion(): void
    {
        // Фасовка «по 6 шт»: 2 порции = 12 шт остатка.
        $eggs = $this->product(1, 1, 6);

        $this->assertSame(12.0, StockUnits::portionsToStock($eggs, 2));
        $this->assertSame(12.0, StockUnits::stockToPortionUnit($eggs, 12.0));
    }

    public function test_missing_base_unit_falls_back_to_large_unit_of_family(): void
    {
        // Весовой товар без base_unit_type_id: остаток 1С весовых — в кг.
        $legacy = $this->product(null, 2, 100);

        $this->assertSame(0.2, StockUnits::portionsToStock($legacy, 2));
        $this->assertSame(0.25, StockUnits::collectedToStock($legacy, 250, 2));
    }

    public function test_product_without_unit_is_counted_in_pieces(): void
    {
        $unknown = $this->product(null, null, null);

        $this->assertSame(2.0, StockUnits::portionsToStock($unknown, 2));
        $this->assertSame(1, StockUnits::portionInSmallest($unknown));
    }

    public function test_portion_size_in_smallest_units(): void
    {
        $this->assertSame(300, StockUnits::portionInSmallest($this->classA()));
        $this->assertSame(1000, StockUnits::portionInSmallest($this->classB()));
        $this->assertSame(1, StockUnits::portionInSmallest($this->classC()));
    }
}
