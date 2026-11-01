<?php

namespace App\Aggregator\Events;

use App\Models\Product;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Бросается из ProductEventTransformer::afterCreate/afterUpdate ПОСЛЕ того как
 * товар и его связанные данные (цены, остатки, штрихкоды) сохранены при импорте
 * из 1С/retailbridge. Безусловно — в форках без aggregator-listener'а это no-op.
 *
 * Слушатель AggregatorOfferImporter (регистрируется только при aggregator_enabled)
 * строит/обновляет платформенную карточку и оффер магазина (см. §6.3).
 *
 * @property Product $product
 * @property array<string, mixed> $payload Исходный payload события (с barcodes, min_qty и т.д.)
 */
class ProductImportedFromEvent
{
  use Dispatchable, SerializesModels;

  public Product $product;

  /** @var array<string, mixed> */
  public array $payload;

  /**
   * @param array<string, mixed> $payload
   */
  public function __construct(Product $product, array $payload)
  {
    $this->product = $product;
    $this->payload = $payload;
  }
}
