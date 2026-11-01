<?php

namespace App\Services;

use App\Models\Price;
use App\Models\Product;
use App\Models\Type;

class OrderCreatorService
{
  public function getQuantityAndPrice(Product $product, Price $price, $quantity): array
  {
    return [
      'product_id' => $product->id,
      'quantity' => $quantity * $price->step,
      'price' => $quantity * $price->value,
      'price_during_order' => $this->getPriceDuringOrder($product, $price),
      'origin_quantity' => $quantity,
      'supplements' => ['amount' => 0],
      'product' => $product,
    ];
  }

  /**
   * Цена за единицу «на момент покупки». Публичный, потому что тем же правилом
   * пересчитываются позиции при смене магазина заказа (сетевой каталог).
   */
  public function getPriceDuringOrder(?Product $product, ?Price $price)
  {
    // Цена за порцию — prices.value для любых товаров: в ней позицию считают и
    // витрина, и курьерка (price_during_order). price_per_kg — цена за кг: у
    // товара с фасовкой 300 г это 594 вместо 178.20 за порцию.
    return match ($product->unit_type_id) {
      Type::UNIT_GRAM, Type::UNIT_PIECE => $price->value,
      default => throw new \Error('Unknown product unit type')
    };
  }
}
