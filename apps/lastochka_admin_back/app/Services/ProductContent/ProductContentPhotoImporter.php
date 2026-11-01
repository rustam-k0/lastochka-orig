<?php

namespace App\Services\ProductContent;

use App\Models\Product;
use App\Models\Status;
use App\Services\ProductPhoto\ProductPhotoDownloader;
use Illuminate\Support\Facades\Log;

/**
 * Скачивание фото товара по URL из файла парсера (LASTOCHKI-JAMI-12027).
 *
 * Первый URL — главное фото (CODE_HEAD_IMG), остальные — слайдер
 * (CODE_SLIDER_IMG). Свой загрузчик вместо DownloadsExternalImages из
 * rabbitmq-пакета: отчёту импорта нужна ПРИЧИНА ошибки по каждому URL,
 * а трейт возвращает только bool.
 */
class ProductContentPhotoImporter
{
  private readonly ProductPhotoDownloader $downloader;

  public function __construct(?ProductPhotoDownloader $downloader = null)
  {
    $this->downloader = $downloader ?? new ProductPhotoDownloader();
  }

  /** @return array{saved: string[], errors: array<string, string>} */
  public function import(Product $product, array $urls): array
  {
    $saved = [];
    $errors = [];

    foreach (array_values($urls) as $i => $url) {
      try {
        $img = $this->downloader->download($url, (string) $product->title);
      } catch (\Throwable $e) {
        $errors[$url] = $e->getMessage();
        Log::warning('Импорт контента: не удалось скачать фото', [
          'product_id' => $product->id,
          'url' => $url,
          'error' => $e->getMessage(),
        ]);
        continue;
      }

      $product->saveImg($img, $i === 0 ? Status::CODE_HEAD_IMG : Status::CODE_SLIDER_IMG);
      $saved[] = $url;
    }

    return ['saved' => $saved, 'errors' => $errors];
  }
}
