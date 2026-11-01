<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use App\Models\Product;
use App\Models\Type;
use App\Services\Pricing\OrderItemTierPricing;
use App\Services\Pricing\PriceTierLadder;
use App\Services\Pricing\PriceTierSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Правило единиц purchase_back для перевода лесенки из базовых единиц в порции.
 * Результат обязан совпадать с delivery_back: снапшот, созданный при смене
 * магазина заказа, дальше считают ProductConfirm и AssemblyFinish.
 */
final class OrderItemTierPricingUnitsTest extends TestCase
{
  private function product(?int $infoWeight, ?int $infoWeightTypeId, ?int $baseUnitTypeId, int $unitTypeId = Type::UNIT_PIECE): Product
  {
    return (new Product())->forceFill([
      'info_weight' => $infoWeight,
      'info_weight_type_id' => $infoWeightTypeId,
      'base_unit_type_id' => $baseUnitTypeId,
      'unit_type_id' => $unitTypeId,
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
    $product = $this->product(null, null, null);

    $this->assertSame(1.0, OrderItemTierPricing::baseSize($product));
    $this->assertSame(1.0, OrderItemTierPricing::stepSmallest($product));
  }

  public function test_kilogram_product_with_200_gram_quantum(): void
  {
    $product = $this->product(200, Type::INFO_WEIGHT_GRAM, Type::INFO_WEIGHT_KILOGRAM);

    $this->assertSame(1000.0, OrderItemTierPricing::baseSize($product));
    $this->assertSame(200.0, OrderItemTierPricing::stepSmallest($product));
    $this->assertSame(
      [['min_quantity' => 10.0, 'price' => 100.0]],
      PriceTierLadder::toPortions(
        PriceTierLadder::normalize([['min_quantity' => 2, 'price' => 500]]),
        OrderItemTierPricing::baseSize($product),
        OrderItemTierPricing::stepSmallest($product)
      )
    );
  }

  public function test_quantum_given_in_kilograms_is_converted_to_grams(): void
  {
    // Квант 2 кг у килограммового товара: шаг = 2000 наименьших единиц
    $product = $this->product(2, Type::INFO_WEIGHT_KILOGRAM, Type::INFO_WEIGHT_KILOGRAM);

    $this->assertSame(2000.0, OrderItemTierPricing::stepSmallest($product));
  }

  public function test_kilogram_product_without_quantum_is_identity(): void
  {
    $product = $this->product(null, Type::INFO_WEIGHT_KILOGRAM, Type::INFO_WEIGHT_KILOGRAM);

    $this->assertSame(1000.0, OrderItemTierPricing::baseSize($product));
    $this->assertSame(1000.0, OrderItemTierPricing::stepSmallest($product));
  }

  public function test_gram_unit_product_with_quantum_uses_quantum_portion(): void
  {
    // unit_type_id = UNIT_GRAM с фасовкой — 982 товара прода Ласточки (порция
    // 300 г, база кг). Порция — квант, как в delivery_back: цена позиции
    // (prices.value) назначена именно за неё.
    $product = $this->product(300, Type::INFO_WEIGHT_GRAM, Type::INFO_WEIGHT_KILOGRAM, Type::UNIT_GRAM);

    $this->assertSame(1000.0, OrderItemTierPricing::baseSize($product));
    $this->assertSame(300.0, OrderItemTierPricing::stepSmallest($product));
  }

  public function test_stub_snapshot_has_contract_shape_and_reads_as_no_snapshot(): void
  {
    $stub = OrderItemTierPricing::stubSnapshot(380.456);

    $this->assertSame([
      'base_price' => 380.46,
      'base_price_old' => null,
      'base_is_eligible' => true,
      'tiers' => [],
    ], $stub);
    $this->assertNull(PriceTierSnapshot::fromArray($stub), 'пустая лесенка = снапшота нет: расчёты идут прежними формулами');
  }
}
