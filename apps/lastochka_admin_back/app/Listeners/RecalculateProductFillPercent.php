<?php

namespace App\Listeners;

use App\Events\ProductUpdated;
use App\Services\ProductFillPercentService;

/**
 * Пересчёт % заполненности карточки при любом изменении товара
 * (LASTOCHKI-JAMI-11653). Только индикатор — никакой авто-(де)публикации,
 * в отличие от легаси UpdateProductFillPercent из ЗЯ.
 */
class RecalculateProductFillPercent
{
  public function __construct(private ProductFillPercentService $service)
  {
  }

  public function handle(ProductUpdated $event): void
  {
    $this->service->recalculate($event->product);
  }
}
