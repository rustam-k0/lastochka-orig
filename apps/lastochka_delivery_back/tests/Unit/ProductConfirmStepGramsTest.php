<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\GraphQL\Mutations\Assembly\ProductConfirm;
use App\Models\Product;
use PHPUnit\Framework\TestCase;

/**
 * Чистый юнит-тест ProductConfirm::getStepGrams — шаг (в граммах) для расчёта
 * цены весового товара по фактическому весу. Без БД/Laravel (голый PHPUnit) —
 * безопасно для prod-БД мины delivery_back.
 *
 * Регрессия tg-14178 / LASTOCHKI-JAMI-11200: fallback для товара без кванта
 * (info_weight пуст) ссылался на несуществующую константу Type::UNIT_FOOD →
 * Fatal Error «Undefined constant» → 500 в ProductConfirm, AssemblyFinish и
 * OrderEventTransformer (метод public static, общий для 7 точек вызова).
 */
final class ProductConfirmStepGramsTest extends TestCase
{
    /**
     * info_weight_type_id: 1=шт, 2=грамм, 3=кг, 4=литр, 5=мл.
     * unit_type_id у весовых 1С-товаров всегда =2 (шт) — намеренно не должен влиять.
     */
    private function product(?int $infoWeight, ?int $infoWeightTypeId, int $unitTypeId = 2): Product
    {
        return (new Product())->forceFill([
            'info_weight'         => $infoWeight,
            'info_weight_type_id' => $infoWeightTypeId,
            'unit_type_id'        => $unitTypeId,
        ]);
    }

    // --- Товар БЕЗ кванта (info_weight NULL/0): порция = 1 крупная единица (1000 наименьших) ---

    public function test_no_quantum_kg_product_returns_1000(): void
    {
        // Прод-кейс tg-14178: товар 8982 (info_weight=NULL, info_weight_type_id=3/кг, unit_type_id=2).
        $this->assertSame(1000, ProductConfirm::getStepGrams($this->product(null, 3)));
    }

    public function test_no_quantum_gram_product_returns_1000(): void
    {
        $this->assertSame(1000, ProductConfirm::getStepGrams($this->product(0, 2)));
    }

    public function test_no_quantum_liter_product_returns_1000(): void
    {
        $this->assertSame(1000, ProductConfirm::getStepGrams($this->product(null, 4)));
    }

    public function test_no_quantum_milliliter_product_returns_1000(): void
    {
        $this->assertSame(1000, ProductConfirm::getStepGrams($this->product(null, 5)));
    }

    public function test_no_quantum_piece_product_returns_1(): void
    {
        $this->assertSame(1, ProductConfirm::getStepGrams($this->product(null, 1)));
    }

    // --- Товар С квантом (info_weight>0): поведение weight>0 ветки не задето фиксом ---

    public function test_quantum_kg_multiplies_by_1000(): void
    {
        $this->assertSame(2000, ProductConfirm::getStepGrams($this->product(2, 3)));
    }

    public function test_quantum_liter_multiplies_by_1000(): void
    {
        $this->assertSame(1000, ProductConfirm::getStepGrams($this->product(1, 4)));
    }

    public function test_quantum_gram_as_is(): void
    {
        $this->assertSame(500, ProductConfirm::getStepGrams($this->product(500, 2)));
    }

    public function test_quantum_milliliter_as_is(): void
    {
        $this->assertSame(330, ProductConfirm::getStepGrams($this->product(330, 5)));
    }

    public function test_quantum_piece_as_is(): void
    {
        $this->assertSame(5, ProductConfirm::getStepGrams($this->product(5, 1)));
    }

    // --- Прод-регрессия: цена позиции из tg-14178 считается без 500 ---

    public function test_production_case_price_math(): void
    {
        // Товар 8982: price_during_order 39 ₽/кг, actual_weight 10000 г (10 кг) → 390 ₽.
        $step  = ProductConfirm::getStepGrams($this->product(null, 3));
        $price = round(39 * 10000 / $step, 2);

        $this->assertSame(1000, $step);
        $this->assertSame(390.0, $price);
    }

    // --- Без кванта при известной базовой единице 1С: порция = 1 базовая единица ---

    public function test_no_quantum_with_gram_base_unit_returns_1(): void
    {
        // Цена value у такого товара — за грамм (admin_back: без кванта value за базу).
        $product = $this->product(null, 2)->forceFill(['base_unit_type_id' => 2]);

        $this->assertSame(1, ProductConfirm::getStepGrams($product));
    }

    public function test_no_quantum_with_kilogram_base_unit_returns_1000(): void
    {
        $product = $this->product(null, 3)->forceFill(['base_unit_type_id' => 3]);

        $this->assertSame(1000, ProductConfirm::getStepGrams($product));
    }

    public function test_no_quantum_with_piece_base_unit_returns_1(): void
    {
        $product = $this->product(null, 1)->forceFill(['base_unit_type_id' => 1]);

        $this->assertSame(1, ProductConfirm::getStepGrams($product));
    }
}
