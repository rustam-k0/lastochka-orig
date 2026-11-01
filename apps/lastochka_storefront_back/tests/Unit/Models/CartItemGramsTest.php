<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\CartItem;
use App\Models\Product;
use Tests\TestCase;

/**
 * MID-259: вес позиции корзины (тариф доставки, is_heavy на чекауте).
 * Для kilogram/liter-товаров без кванта quantity корзины хранится в кг/л
 * (CreateOrderAction переводит в граммы при создании позиции заказа).
 */
final class CartItemGramsTest extends TestCase
{
    private function item(int $quantity, array $productAttrs): CartItem
    {
        $product = (new Product())->forceFill(array_merge([
            'weight' => null,
            'measurement_unit' => 'piece',
            'quantity_step' => 1,
        ], $productAttrs));

        $item = (new CartItem())->forceFill(['quantity' => $quantity]);
        $item->setRelation('product', $product);

        return $item;
    }

    public function test_piece_item_uses_product_weight_times_quantity(): void
    {
        $this->assertSame(454, $this->item(2, ['weight' => 227])->grams());
    }

    public function test_piece_item_without_weight_is_unknown(): void
    {
        $this->assertNull($this->item(2, ['weight' => null])->grams());
    }

    public function test_step_product_quantity_is_already_grams(): void
    {
        $this->assertSame(850, $this->item(850, ['measurement_unit' => 'gram', 'quantity_step' => 50])->grams());
    }

    public function test_kilogram_product_without_step_quantity_is_kilograms(): void
    {
        // Курица: корзина хранит 1 (кг), в заказ уйдёт 1000 gram
        $this->assertSame(1000, $this->item(1, ['measurement_unit' => 'kilogram', 'quantity_step' => 1])->grams());
    }

    public function test_liter_product_without_step_quantity_is_liters(): void
    {
        $this->assertSame(2000, $this->item(2, ['measurement_unit' => 'liter', 'quantity_step' => 1])->grams());
    }

    public function test_gram_product_without_step_quantity_is_grams(): void
    {
        $this->assertSame(300, $this->item(300, ['measurement_unit' => 'gram', 'quantity_step' => 1])->grams());
    }

    public function test_item_without_product_is_unknown(): void
    {
        $item = (new CartItem())->forceFill(['quantity' => 3]);
        $item->setRelation('product', null);

        $this->assertNull($item->grams());
    }
}
