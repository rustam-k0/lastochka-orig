<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\ProductFillPercentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Асинхронный bulk-пересчёт fill_percent для списка товаров. Диспатчится
 * из ProductBatchHandler после bulk-записи цен, чтобы тяжёлый пересчёт
 * (гидратация attributes/img/categories) не блокировал дренаж батч-очереди.
 * fill_percent не критичен по времени — eventual consistency достаточно.
 *
 * В тестах QUEUE_CONNECTION=sync → джоба выполняется inline, поэтому
 * эквивалентность с per-item путём проверяется теми же тестами.
 */
class RecalculateProductFillPercentJob implements ShouldQueue
{
  use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

  public int $timeout = 600;

  /** @param array<int> $productIds */
  public function __construct(public array $productIds)
  {
  }

  public function handle(ProductFillPercentService $fill): void
  {
    collect(array_unique($this->productIds))
      ->chunk(50)
      ->each(function ($ids) use ($fill) {
        Product::with(['img', 'categories', 'prices', 'attributes.attribute.defaultAttribute'])
          ->whereIn('id', $ids->all())
          ->get()
          ->each(fn (Product $p) => $fill->recalculate($p, false));
      });
  }
}
