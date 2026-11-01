<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Pricing\OrderItemPortions;
use Tests\TestCase;

class OrderItemPortionsTest extends TestCase
{
    private function product(string $unit, int $step): Product
    {
        return new Product(['measurement_unit' => $unit, 'quantity_step' => $step]);
    }

    public function test_piece_item_portions_equal_quantity(): void
    {
        $this->assertSame(5.0, OrderItemPortions::fromStored(5, 'piece', $this->product('piece', 1)));
        $this->assertSame(5.0, OrderItemPortions::fromStored(5, null, null));
    }

    public function test_quantum_item_divides_grams_by_step(): void
    {
        $this->assertSame(10.0, OrderItemPortions::fromStored(2000, 'gram', $this->product('gram', 200)));
    }

    /**
     * После сборки весовая позиция хранит фактический вес: недовес в пределах
     * шага ступень не срывает (порции = округлённый вес / шаг).
     */
    public function test_actual_weight_is_rounded_to_portions(): void
    {
        $quantum = $this->product('gram', 200);

        $this->assertSame(10.0, OrderItemPortions::fromStored(1930, 'gram', $quantum), '1930 г при шаге 200 г = 10 порций');
        $this->assertSame(9.0, OrderItemPortions::fromStored(1890, 'gram', $quantum));
        $this->assertSame(1.0, OrderItemPortions::fromStored(60, 'gram', $quantum), 'не меньше одной порции');
    }

    public function test_kilogram_product_without_quantum_is_stored_in_grams(): void
    {
        $weight = $this->product('kilogram', 1);

        $this->assertSame(2.0, OrderItemPortions::fromStored(2000, 'gram', $weight));
        $this->assertSame(2.0, OrderItemPortions::fromStored(1930, 'gram', $weight));
        $this->assertSame(2.0, OrderItemPortions::fromStored(2, 'kilogram', $weight), 'позиция дозаказа хранит килограммы');
    }

    public function test_of_reads_order_item_fields(): void
    {
        $item = new OrderItem(['quantity' => 1000, 'measurement_unit' => 'gram']);
        $item->setRelation('product', $this->product('gram', 500));

        $this->assertSame(2.0, OrderItemPortions::of($item));
    }

    public function test_stored_from_cart_mirrors_create_order_rule(): void
    {
        $this->assertSame(2000, OrderItemPortions::storedFromCart(2, $this->product('kilogram', 1)));
        $this->assertSame(1000, OrderItemPortions::storedFromCart(1000, $this->product('kilogram', 500)));
        $this->assertSame(400, OrderItemPortions::storedFromCart(400, $this->product('gram', 200)));
        $this->assertSame(3, OrderItemPortions::storedFromCart(3, $this->product('piece', 1)));
    }
}
