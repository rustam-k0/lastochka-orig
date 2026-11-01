<?php

namespace App\Filters;

use App\Models\Order;

class ProductFilter extends QueryFilter
{

  public function order_id($value)
  {
    $this->builder->whereHas('orderItem.order', function ($query) use ($value) {
      $query->where((new Order())->getTable() . '.id', $value);
    });
  }

  public function barcode($value)
  {
    // Точное совпадение по сохранённому штрихкоду приоритетнее разбора весового
    // (20–29 + код 1С) — см. Product::scopeScannedBarcode.
    $this->builder->scannedBarcode((string) $value);
  }

  public function articul($value)
  {
    // Цифры с ценника: и артикул, и код 1С, толерантно к разделителям разрядов.
    $this->builder->matchingCode((string) $value);
  }
}
