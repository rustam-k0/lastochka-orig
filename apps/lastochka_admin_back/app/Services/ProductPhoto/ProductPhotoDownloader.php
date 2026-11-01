<?php

namespace App\Services\ProductPhoto;

use App\Helpers\Upload;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Скачивание фото товара по URL в Storage: jpeg/png/webp до 10 МБ.
 * Общий для импорта контента (LASTOCHKI-JAMI-12027) и обмена 1С (MID-305).
 */
class ProductPhotoDownloader
{
  private const MIME_TO_EXT = [
    'image/jpeg' => 'jpg',
    'image/jpg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
  ];

  /**
   * Лимит размера фото, дублирует «до ~10 МБ» из инструкции листа выгрузки
   * (ProductContentSchema::instructionRows()) и docs/product-content-parser-guide.md.
   */
  private const MAX_BYTES = 10 * 1024 * 1024;

  /**
   * @return array{path: string, origin_name: string, alt: string, size: string, type: string}
   * @throws \RuntimeException если URL не отдал картинку
   */
  public function download(string $url, string $alt, string $folder = 'products/parser'): array
  {
    // idn_conversion: кириллический домен (русский-разгуляйка.рф из 1С) уходит
    // punycode-хостом, сырой UTF-8 в Host nginx отбивает ответом 400.
    $response = Http::timeout(30)
      ->withOptions(['idn_conversion' => true])
      ->retry(2, 1000, throw: false)
      ->get($url);

    if (!$response->successful()) {
      throw new \RuntimeException("HTTP {$response->status()}");
    }

    $contentType = mb_strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
    $extension = self::MIME_TO_EXT[$contentType] ?? null;

    if ($extension === null) {
      throw new \RuntimeException("не изображение (Content-Type: {$contentType})");
    }

    // strlen(), а НЕ mb_strlen() — тело ответа это бинарные данные изображения,
    // а не текстовая строка, тут нужен именно подсчёт байтов.
    if (strlen($response->body()) > self::MAX_BYTES) {
      throw new \RuntimeException('изображение больше 10 МБ');
    }

    $relative = $folder . '/' . Str::random(40) . '.' . $extension;

    if (Storage::put($relative, $response->body()) === false) {
      throw new \RuntimeException('не удалось сохранить файл в Storage');
    }

    $originName = Str::of((string) parse_url($url, PHP_URL_PATH))->afterLast('/')->toString()
      ?: 'parser.' . $extension;

    return [
      'path' => Storage::url($relative),
      'origin_name' => $originName,
      'alt' => $alt,
      'size' => Upload::humanReadableSize(Storage::size($relative)),
      'type' => $extension,
    ];
  }
}
