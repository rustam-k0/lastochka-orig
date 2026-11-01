<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\GraphQL\Mutations\Assembly\AssemblyFinish;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Status;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тест AssemblyFinish::calculateOrderWeight — суммарный вес заказа
 * в граммах по финализированному составу order_items (LASTOCHKI-JAMI-11656).
 * Без БД/Laravel (голый PHPUnit) — безопасно для prod-БД мины delivery_back.
 *
 * Правила вклада позиции:
 *  - отменена (ORDER_ITEM_CANCELED)                  → пропуск;
 *  - actual_weight задан (весовой, взвешен)          → actual_weight как есть;
 *  - весовой/объёмный без actual_weight (порции)     → quantity × getStepGrams;
 *  - штучный (info_weight_type_id=1/NULL) с weight   → product.weight × quantity;
 *  - штучный без weight / product отсутствует        → пропуск + is_weight_partial.
 */
final class OrderWeightCalculationTest extends TestCase
{
    /**
     * info_weight_type_id: 1=шт, 2=грамм, 3=кг, 4=литр, 5=мл.
     */
    private function item(
        ?int $actualWeight,
        int $quantity,
        ?Product $product,
        int $statusId = Status::ORDER_ITEM_ASSEMBLED
    ): OrderItem {
        $item = (new OrderItem())->forceFill([
            'actual_weight' => $actualWeight,
            'quantity'      => $quantity,
            'status_id'     => $statusId,
        ]);
        $item->setRelation('product', $product);

        return $item;
    }

    private function pieceProduct(?int $weight): Product
    {
        return (new Product())->forceFill([
            'weight'              => $weight,
            'info_weight'         => null,
            'info_weight_type_id' => 1, // шт
        ]);
    }

    private function weightedProduct(?int $infoWeight, int $infoWeightTypeId): Product
    {
        return (new Product())->forceFill([
            'weight'              => null,
            'info_weight'         => $infoWeight,
            'info_weight_type_id' => $infoWeightTypeId,
        ]);
    }

    public function test_weighed_item_contributes_actual_weight(): void
    {
        $result = AssemblyFinish::calculateOrderWeight(collect([
            $this->item(700, 3, $this->weightedProduct(250, 2)),
        ]));

        $this->assertSame(700, $result['total_weight']);
        $this->assertFalse($result['is_weight_partial']);
    }

    public function test_weighted_item_without_actual_weight_uses_portions_times_step(): void
    {
        // Квант 250 г, 2 порции → 500 г номинала
        $result = AssemblyFinish::calculateOrderWeight(collect([
            $this->item(null, 2, $this->weightedProduct(250, 2)),
        ]));

        $this->assertSame(500, $result['total_weight']);
        $this->assertFalse($result['is_weight_partial']);
    }

    public function test_weighted_kg_item_without_quantum_uses_1000g_step(): void
    {
        // Товар в кг без кванта: getStepGrams → 1000; 2 порции → 2000 г
        $result = AssemblyFinish::calculateOrderWeight(collect([
            $this->item(null, 2, $this->weightedProduct(null, 3)),
        ]));

        $this->assertSame(2000, $result['total_weight']);
        $this->assertFalse($result['is_weight_partial']);
    }

    public function test_piece_item_contributes_weight_times_quantity(): void
    {
        $result = AssemblyFinish::calculateOrderWeight(collect([
            $this->item(null, 2, $this->pieceProduct(200)),
        ]));

        $this->assertSame(400, $result['total_weight']);
        $this->assertFalse($result['is_weight_partial']);
    }

    public function test_piece_item_without_weight_sets_partial_flag(): void
    {
        $result = AssemblyFinish::calculateOrderWeight(collect([
            $this->item(null, 2, $this->pieceProduct(null)),
            $this->item(null, 1, $this->pieceProduct(300)),
        ]));

        $this->assertSame(300, $result['total_weight']);
        $this->assertTrue($result['is_weight_partial']);
    }

    public function test_piece_item_with_null_info_weight_type_is_treated_as_piece(): void
    {
        $product = (new Product())->forceFill([
            'weight'              => 150,
            'info_weight'         => null,
            'info_weight_type_id' => null,
        ]);

        $result = AssemblyFinish::calculateOrderWeight(collect([
            $this->item(null, 3, $product),
        ]));

        $this->assertSame(450, $result['total_weight']);
        $this->assertFalse($result['is_weight_partial']);
    }

    public function test_cancelled_item_is_skipped(): void
    {
        $result = AssemblyFinish::calculateOrderWeight(collect([
            $this->item(null, 1, $this->pieceProduct(500), Status::ORDER_ITEM_CANCELED),
            $this->item(null, 1, $this->pieceProduct(100)),
        ]));

        $this->assertSame(100, $result['total_weight']);
        $this->assertFalse($result['is_weight_partial']);
    }

    public function test_missing_product_sets_partial_flag(): void
    {
        $result = AssemblyFinish::calculateOrderWeight(collect([
            $this->item(null, 1, null),
        ]));

        $this->assertSame(0, $result['total_weight']);
        $this->assertTrue($result['is_weight_partial']);
    }

    public function test_mixed_order(): void
    {
        $result = AssemblyFinish::calculateOrderWeight(collect([
            $this->item(700, 3, $this->weightedProduct(250, 2)),                          // 700
            $this->item(null, 2, $this->pieceProduct(200)),                               // 400
            $this->item(null, 2, $this->pieceProduct(null)),                              // 0 + partial
            $this->item(null, 1, $this->pieceProduct(999), Status::ORDER_ITEM_CANCELED), // 0
        ]));

        $this->assertSame(1100, $result['total_weight']);
        $this->assertTrue($result['is_weight_partial']);
    }
}
