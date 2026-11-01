<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use App\Models\Product;
use App\Models\Type;
use App\Services\Pricing\OrderItemTierPricing;
use App\Services\Pricing\PriceTierLadder;
use PHPUnit\Framework\TestCase;

/**
 * Правило единиц delivery_back для перевода лесенки из базовых единиц в порции:
 * baseSize из base_unit_type_id (fallback info_weight_type_id), stepSmallest из
 * кванта (ProductConfirm::getStepGrams) либо, без кванта, равен baseSize.
 */
final class OrderItemTierPricingUnitsTest extends TestCase
{
  private function product(?int $infoWeight, ?int $infoWeightTypeId, ?int $baseUnitTypeId): Product
  {
    return (new Product())->forceFill([
      'info_weight' => $infoWeight,
      'info_weight_type_id' => $infoWeightTypeId,
      'base_unit_type_id' => $baseUnitTypeId,
      'unit_type_id' => Type::UNIT_PIECE,
    ]);
  }

  public function test_piece_product_is_identity(): void
  {
    $product = $this->product(null, Type::INFO_WEIGHT_PIECE, Type::INFO_WEIGHT_PIECE);

    $this->assertSame(1.0, OrderItemTierPricing::baseSize($product));
    $this->assertSame(1.0, OrderItemTierPricing::stepSmallest($product));
  }

  public function test_piece_product_without_unit_from_1c_is_identity(): void
  {
    // Товары 1С без единицы: info_weight_type_id NULL. getStepGrams() для них дал бы
    // 1000, но весом они не продаются: порция = 1 шт.
    $product = $this->product(null, null, null);

    $this->assertSame(1.0, OrderItemTierPricing::baseSize($product));
    $this->assertSame(1.0, OrderItemTierPricing::stepSmallest($product));
  }

  public function test_kilogram_product_with_200_gram_quantum(): void
  {
    // admin_back при кванте пишет info_weight = 200, info_weight_type_id = грамм,
    // а сырая единица 1С остаётся в base_unit_type_id = килограмм
    $product = $this->product(200, Type::INFO_WEIGHT_GRAM, Type::INFO_WEIGHT_KILOGRAM);

    $this->assertSame(1000.0, OrderItemTierPricing::baseSize($product));
    $this->assertSame(200.0, OrderItemTierPricing::stepSmallest($product));

    // «от 2 кг по 500 ₽ за кг» → «от 10 порций по 100 ₽ за порцию»
    $this->assertSame(
      [['min_quantity' => 10.0, 'price' => 100.0]],
      PriceTierLadder::toPortions(
        PriceTierLadder::normalize([['min_quantity' => 2, 'price' => 500]]),
        OrderItemTierPricing::baseSize($product),
        OrderItemTierPricing::stepSmallest($product)
      )
    );
  }

  public function test_kilogram_product_without_quantum_is_identity(): void
  {
    $product = $this->product(null, Type::INFO_WEIGHT_KILOGRAM, Type::INFO_WEIGHT_KILOGRAM);

    $this->assertSame(1000.0, OrderItemTierPricing::baseSize($product));
    $this->assertSame(1000.0, OrderItemTierPricing::stepSmallest($product));
  }

  public function test_manual_product_without_base_unit_falls_back_to_card_unit(): void
  {
    $product = $this->product(null, Type::INFO_WEIGHT_LITER, null);

    $this->assertSame(1000.0, OrderItemTierPricing::baseSize($product));
    $this->assertSame(1000.0, OrderItemTierPricing::stepSmallest($product));
  }

  public function test_piece_quantum_of_six_units(): void
  {
    $product = $this->product(6, Type::INFO_WEIGHT_PIECE, Type::INFO_WEIGHT_PIECE);

    $this->assertSame(1.0, OrderItemTierPricing::baseSize($product));
    $this->assertSame(6.0, OrderItemTierPricing::stepSmallest($product));
  }

  public function test_weight_portions_round_to_nearest_step_with_minimum_one(): void
  {
    $product = $this->product(200, Type::INFO_WEIGHT_GRAM, Type::INFO_WEIGHT_KILOGRAM);

    $this->assertSame(5.0, OrderItemTierPricing::weightPortions(960, $product), 'недовес 40 г: 4.8 → 5 порций');
    $this->assertSame(4.0, OrderItemTierPricing::weightPortions(880, $product), '4.4 → 4 порции');
    $this->assertSame(1.0, OrderItemTierPricing::weightPortions(40, $product), 'меньше половины шага: минимум 1 порция');
  }
}
