<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\ProductFillPercentService;
use Illuminate\Console\Command;

/**
 * Бэкфил/пересчёт % заполненности карточек товаров (LASTOCHKI-JAMI-11653).
 * One-off после деплоя и страховка от дрейфа после массовых 1С-импортов.
 */
class RecalculateProductsFillPercent extends Command
{
  protected $signature = 'products:recalculate-fill-percent
    {--shop_id= : Ограничить одним магазином}
    {--chunk=200 : Размер чанка}';

  protected $description = 'Пересчитать процент заполненности карточек товаров';

  public function handle(ProductFillPercentService $service): int
  {
    $query = Product::query()
      ->with(['img', 'categories', 'prices', 'attributes.attribute.defaultAttribute'])
      ->when($this->option('shop_id'), fn ($q, $shopId) => $q->where('shop_id', $shopId));

    $total = (clone $query)->count();
    $this->info("Товаров к пересчёту: {$total}");

    $bar = $this->output->createProgressBar($total);
    $query->chunkById((int) $this->option('chunk'), function ($products) use ($service, $bar) {
      foreach ($products as $product) {
        $service->recalculate($product, refresh: false);
        $bar->advance();
      }
    });
    $bar->finish();
    $this->newLine();
    $this->info('Готово.');

    return self::SUCCESS;
  }
}
