<?php

namespace App\GraphQL\Queries\Product;

use App\Models\Order;
use App\Models\Product;
use App\Support\NetworkCatalogMode;

final class ProductSearchByShop
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $order = Order::find($args['order_id']);

    if (!$order) {
      return null;
    }

    // Сетевой каталог: карточка товара глобальная (products.shop_id = якорь),
    // принадлежность магазину определяет остаток, а не колонка карточки.
    $query = NetworkCatalogMode::isEnabled()
      ? Product::query()->inStock($order->shop_id)
      : Product::where('shop_id', $order->shop_id)->inStock();

    if (!empty($args['barcode'])) {
      // Точное совпадение по сохранённому штрихкоду приоритетнее разбора весового
      // (20–29 + код 1С) — см. Product::scopeScannedBarcode.
      $query->scannedBarcode((string) $args['barcode']);
    }

    if (!empty($args['articul'])) {
      // Цифры с ценника: ищем и по артикулу, и по коду 1С, толерантно к
      // разделителям разрядов — см. Product::scopeMatchingCode.
      $query->matchingCode((string) $args['articul']);
    }

    return $query->first();
  }
}
