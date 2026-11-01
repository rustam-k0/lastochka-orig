<?php

namespace App\Jobs\OneC;

use App\Events\ProductUpdated;
use App\Models\Product;
use App\Services\OneC\OneCProductImageSync;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Фото товара из 1С в фоне (MID-305): скачивание картинок внутри обработки
 * батча карточек растянуло бы полную выгрузку на часы. После изменения фото
 * публикует ProductUpdated — витрина получает итоговый набор media от курьерки.
 *
 * Устаревшая задача (по товару уже пришло более свежее событие) пропускается,
 * иначе при двух обменах подряд старый URL перезаписал бы новый.
 */
class SyncProductImagesFromOneCJob implements ShouldQueue
{
  use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

  public int $tries = 3;

  public int $backoff = 60;

  /**
   * Меньше retry_after очереди (90 с в config/queue.php): иначе воркер с
   * таймаутом по умолчанию (60 с) убил бы задачу посреди скачивания нескольких
   * фото (таймаут загрузки 30 с на файл), а при таймауте больше retry_after
   * очередь выдала бы ту же задачу второму воркеру, пока первый ещё качает.
   */
  public int $timeout = 85;

  public function __construct(
    public int $productId,
    public array $desired,
    public string $eventTimestamp
  ) {
    $this->onQueue(config('services.onec_photos.queue', 'default'));
  }

  public static function latestKey(int $productId): string
  {
    return 'onec_images_latest:' . $productId;
  }

  public function handle(OneCProductImageSync $sync): void
  {
    $latest = Cache::get(self::latestKey($this->productId));
    if ($latest !== null && Carbon::parse($latest)->gt(Carbon::parse($this->eventTimestamp))) {
      return;
    }

    $product = Product::query()->find($this->productId);
    if ($product === null) {
      return;
    }

    if ($sync->sync($product, $this->desired)) {
      event(new ProductUpdated($product->fresh()));
    }
  }
}
