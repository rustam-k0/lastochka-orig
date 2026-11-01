<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\OrderItem;
use App\Models\Product;
use Tests\TestCase;

/**
 * MID-259: вес позиции заказа для плановой оценки веса (total_weight / is_heavy
 * на чекауте). Весовые товары хранят quantity в граммах — у них нет product.weight,
 * и до фикса такая позиция выпадала из расчёта: заказ с курицей на 1 кг весил
 * «как кофе», плашка «Тяжёлый заказ» появлялась только после сборки.
 */
final class OrderItemGramsTest extends TestCase
{
    private function item(array $itemAttrs, array $productAttrs): OrderItem
    {
        $product = (new Product())->forceFill(array_merge([
            'weight' => null,
            'measurement_unit' => 'piece',
            'quantity_step' => 1,
        ], $productAttrs));

        $item = (new OrderItem())->forceFill(array_merge([
            'quantity' => 1,
            'actual_weight' => null,
            'measurement_unit' => 'piece',
        ], $itemAttrs));
        $item->setRelation('product', $product);

        return $item;
    }

    public function test_piece_item_uses_product_weight_times_quantity(): void
    {
        $item = $this->item(['quantity' => 2], ['weight' => 227]);

        $this->assertSame(454, $item->grams());
    }

    public function test_piece_item_without_product_weight_is_unknown(): void
    {
        $item = $this->item(['quantity' => 2], ['weight' => null]);

        $this->assertNull($item->grams());
    }

    public function test_actual_weight_has_priority(): void
    {
        $item = $this->item(['quantity' => 2, 'actual_weight' => 900], ['weight' => 227]);

        $this->assertSame(900, $item->grams());
    }

    public function test_kilogram_product_stored_in_grams_counts_quantity_as_grams(): void
    {
        // Курица «1 кг»: product.measurement_unit=kilogram, step=1, weight=null;
        // order_item хранит 1000 gram — это и есть вес позиции
        $item = $this->item(
            ['quantity' => 1000, 'measurement_unit' => 'gram'],
            ['measurement_unit' => 'kilogram', 'quantity_step' => 1, 'weight' => null],
        );

        $this->assertSame(1000, $item->grams());
    }

    public function test_gram_product_with_step_counts_quantity_as_grams(): void
    {
        $item = $this->item(
            ['quantity' => 850, 'measurement_unit' => 'gram'],
            ['measurement_unit' => 'gram', 'quantity_step' => 50, 'weight' => null],
        );

        $this->assertSame(850, $item->grams());
    }

    public function test_milliliter_product_counts_quantity_as_grams(): void
    {
        $item = $this->item(
            ['quantity' => 500, 'measurement_unit' => 'milliliter'],
            ['measurement_unit' => 'liter', 'quantity_step' => 1, 'weight' => null],
        );

        $this->assertSame(500, $item->grams());
    }

    public function test_weighted_item_without_product_relation_still_counts_grams(): void
    {
        // Товар удалён/не догружен, но единица хранения позиции — граммы
        $item = (new OrderItem())->forceFill([
            'quantity' => 1000,
            'actual_weight' => null,
            'measurement_unit' => 'gram',
        ]);
        $item->setRelation('product', null);

        $this->assertSame(1000, $item->grams());
    }
}
