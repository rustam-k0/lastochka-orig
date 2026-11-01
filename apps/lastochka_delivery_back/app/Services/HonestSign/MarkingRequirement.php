<?php

namespace App\Services\HonestSign;

use App\Models\Assembly;
use App\Models\AssemblyItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Status;

/**
 * Требование к кодам маркировки при подтверждении позиции / завершении
 * сборки (Честный знак, MID-266).
 *
 * Правило: маркированный штучный товар — по коду на каждую собранную
 * единицу; маркированный весовой — один код (взвешивается одна единица).
 * Учитываются только коды товара, который реально подтверждается
 * (при замене — товара-замены, коды оригинала не считаются).
 *
 * Все проверки — no-op при выключенном модуле honest_sign.
 */
final class MarkingRequirement
{
  public static function enabled(): bool
  {
    return Order::isHonestSignModuleEnabled();
  }

  /**
   * Сколько кодов нужно для подтверждения товара.
   */
  public static function required(Product $product, ?int $quantity, ?int $actualWeight, ?OrderItem $orderItem): int
  {
    if (!$product->is_marked) {
      return 0;
    }

    if ($actualWeight !== null && $product->isWeightBased()) {
      return 1;
    }

    return max(0, (int) ($quantity ?? $orderItem?->quantity ?? 1));
  }

  /**
   * Сколько кодов уже отсканировано у позиции для товара.
   */
  public static function scanned(AssemblyItem $item, int $productId): int
  {
    return $item->markingCodes()->where('product_id', $productId)->count();
  }

  /**
   * Текст ошибки, если кодов не столько, сколько требуется; null — всё сходится.
   */
  public static function check(AssemblyItem $item, Product $product, int $required): ?string
  {
    if ($required <= 0) {
      return null;
    }

    $scanned = self::scanned($item, (int) $product->id);

    if ($scanned < $required) {
      return "Отсканируйте коды маркировки: {$scanned} из {$required} ({$product->title})";
    }

    if ($scanned > $required) {
      return "Отсканировано кодов маркировки больше, чем собрано ({$scanned} из {$required}), удалите лишние ({$product->title})";
    }

    return null;
  }

  /**
   * Гейт ProductConfirm: подтверждаемый товар — замена или товар позиции.
   */
  public static function errorForConfirm(AssemblyItem $item, ?int $replacementProductId, ?int $quantity, ?int $actualWeight): ?string
  {
    if (!self::enabled()) {
      return null;
    }

    $orderItem = $item->orderItem;
    $product = $replacementProductId ? Product::find($replacementProductId) : $orderItem?->product;

    if (!$product) {
      return null;
    }

    return self::check($item, $product, self::required($product, $quantity, $actualWeight, $orderItem));
  }

  /**
   * Гейт AssemblyFinish: у каждой собранной маркированной позиции ровно
   * столько кодов, сколько собрано единиц (защита от обхода ProductConfirm).
   */
  public static function errorForAssembly(Assembly $assembly): ?string
  {
    if (!self::enabled()) {
      return null;
    }

    $items = $assembly->assemblyItems()
      ->where('status_id', Status::PRODUCT_ASSEMBLED)
      ->with(['orderItem.product', 'replacements.product'])
      ->get();

    foreach ($items as $item) {
      $selected = $item->replacements->first(fn($replacement) => (bool) $replacement->selected);
      $product = $selected?->product ?? $item->orderItem?->product;

      if (!$product || !$product->is_marked) {
        continue;
      }

      $required = $item->actual_weight !== null && $product->isWeightBased()
        ? 1
        : max(0, (int) ($item->quantity ?? $item->orderItem?->quantity ?? 1));

      if ($error = self::check($item, $product, $required)) {
        return $error;
      }
    }

    return null;
  }
}
