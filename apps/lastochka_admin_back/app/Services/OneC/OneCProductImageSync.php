<?php

namespace App\Services\OneC;

use App\Models\Img;
use App\Models\JoinImg;
use App\Models\Product;
use App\Models\Status;
use App\Services\ProductPhoto\ProductPhotoDownloader;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Фото товара из 1С, когда 1С ведёт карточку (MID-305).
 *
 * image_url — главное фото, additional_image_urls — слайдер в порядке списка.
 * Ключа нет — не трогаем; null/пусто — удаляем; тот же URL (img.source_url) —
 * повторно не качаем. Ручные фото заменяются: 1С главнее.
 */
class OneCProductImageSync
{
  public const FOLDER = 'products/onec';

  /** Длина колонки img.source_url. */
  public const MAX_URL_LENGTH = 1000;

  public function __construct(private readonly ProductPhotoDownloader $downloader)
  {
  }

  /**
   * @return array{main_provided: bool, main_url: ?string, additional_provided: bool, additional_urls: ?array}
   */
  public static function desiredFromEvent(array $eventData): array
  {
    $mainProvided = array_key_exists('image_url', $eventData);

    $rawAdditional = $eventData['additional_image_urls'] ?? null;
    $additionalProvided = array_key_exists('additional_image_urls', $eventData)
      && ($rawAdditional === null || is_array($rawAdditional));

    // Ссылка длиннее колонки img.source_url — битые данные, а не команда
    // «удалить фото»: главное фото и слайдер в этом случае не трогаем.
    if ($mainProvided && self::isTooLongUrl($eventData['image_url'])) {
      self::logTooLongUrl('image_url', $eventData);
      $mainProvided = false;
    }

    if ($additionalProvided && is_array($rawAdditional)) {
      foreach ($rawAdditional as $url) {
        if (self::isTooLongUrl($url)) {
          self::logTooLongUrl('additional_image_urls', $eventData);
          $additionalProvided = false;
          break;
        }
      }
    }

    $additionalUrls = null;
    if ($additionalProvided) {
      $additionalUrls = [];
      foreach ((array) $rawAdditional as $url) {
        $normalized = self::normalizeUrl($url);
        if ($normalized !== null && !in_array($normalized, $additionalUrls, true)) {
          $additionalUrls[] = $normalized;
        }
      }
    }

    return [
      'main_provided' => $mainProvided,
      'main_url' => $mainProvided ? self::normalizeUrl($eventData['image_url']) : null,
      'additional_provided' => $additionalProvided,
      'additional_urls' => $additionalUrls,
    ];
  }

  public function needsSync(Product $product, array $desired): bool
  {
    if ($desired['main_provided']) {
      $mainJoin = $this->mainJoin($product);
      $currentSource = $mainJoin?->img?->source_url;
      $hasMain = $mainJoin !== null;

      if ($desired['main_url'] === null ? $hasMain : $currentSource !== $desired['main_url']) {
        return true;
      }
    }

    if ($desired['additional_provided']) {
      return $this->sliderSources($this->sliderJoins($product)) !== $desired['additional_urls'];
    }

    return false;
  }

  /**
   * @throws \RuntimeException все нужные скачивания упали и ничего не изменилось (ретрай очереди)
   */
  public function sync(Product $product, array $desired): bool
  {
    $changed = false;
    $failed = false;

    if ($desired['main_provided']) {
      $join = $this->mainJoin($product);

      if ($desired['main_url'] === null) {
        if ($join !== null) {
          $product->deleteImg($join);
          $changed = true;
        }
      } elseif ($join?->img?->source_url !== $desired['main_url']) {
        try {
          $attrs = $this->download($desired['main_url'], $product);
          if ($join !== null && $join->img !== null) {
            $product->updateImg($join, $attrs);
          } else {
            $product->saveImg($attrs, Status::CODE_HEAD_IMG);
          }
          $changed = true;
        } catch (\Throwable $e) {
          $failed = true;
          $this->logFailure($product, $desired['main_url'], $e);
        }
      }
    }

    if ($desired['additional_provided']) {
      $joins = $this->sliderJoins($product);
      $urls = $desired['additional_urls'];

      if ($this->sliderSources($joins) !== $urls) {
        $imgIds = [];
        $downloaded = [];
        foreach ($joins as $existing) {
          if ($existing->img?->source_url !== null) {
            $downloaded[$existing->img->source_url] = $existing->img_id;
          }
        }

        foreach ($urls as $url) {
          if (isset($downloaded[$url])) {
            $imgIds[] = $downloaded[$url];
            continue;
          }

          try {
            $imgIds[] = Img::create($this->download($url, $product))->id;
          } catch (\Throwable $e) {
            $failed = true;
            $this->logFailure($product, $url, $e);
          }
        }

        if ($urls === [] || $imgIds !== []) {
          foreach ($joins as $existing) {
            $product->deleteImg($existing);
          }
          foreach ($imgIds as $imgId) {
            JoinImg::create([
              'item_id' => $product->id,
              'img_id' => $imgId,
              'head_id' => Status::CODE_SLIDER_IMG,
              'table_name' => $product->getTable(),
            ]);
          }
          $changed = true;
        }
      }
    }

    if ($failed && !$changed) {
      throw new \RuntimeException("1С: не удалось скачать фото товара {$product->id}");
    }

    return $changed;
  }

  /**
   * URL длиннее колонки img.source_url (VARCHAR 1000) отбрасывается как
   * пустой: записать его источником нельзя (запись упадёт или URL обрежется),
   * и сравнение «уже скачано» с ним никогда не совпало бы.
   */
  private static function isTooLongUrl(mixed $url): bool
  {
    return is_string($url) && mb_strlen(trim($url)) > self::MAX_URL_LENGTH;
  }

  private static function logTooLongUrl(string $key, array $eventData): void
  {
    Log::warning('1С: ссылка на фото длиннее ' . self::MAX_URL_LENGTH . ' символов, фото не изменены', [
      'key' => $key,
      'external_id' => $eventData['id'] ?? null,
      'event_id' => $eventData['event_id'] ?? null,
    ]);
  }

  private static function normalizeUrl(mixed $url): ?string
  {
    if (!is_string($url)) {
      return null;
    }

    $trimmed = trim($url);

    if ($trimmed === '' || mb_strlen($trimmed) > self::MAX_URL_LENGTH) {
      return null;
    }

    return $trimmed;
  }

  private function download(string $url, Product $product): array
  {
    $attrs = $this->downloader->download($url, (string) $product->title, self::FOLDER);
    $attrs['source_url'] = $url;

    return $attrs;
  }

  private function mainJoin(Product $product): ?JoinImg
  {
    return JoinImg::query()
      ->with('img')
      ->where('item_id', $product->id)
      ->where('table_name', $product->getTable())
      ->where('head_id', Status::CODE_HEAD_IMG)
      ->orderByDesc('id')
      ->first();
  }

  private function sliderJoins(Product $product): Collection
  {
    return JoinImg::query()
      ->with('img')
      ->where('item_id', $product->id)
      ->where('table_name', $product->getTable())
      ->where('head_id', Status::CODE_SLIDER_IMG)
      ->orderBy('id')
      ->get();
  }

  private function sliderSources(Collection $joins): array
  {
    return $joins->map(fn (JoinImg $join) => $join->img?->source_url)->values()->all();
  }

  private function logFailure(Product $product, string $url, \Throwable $e): void
  {
    Log::warning('1С: не удалось скачать фото товара', [
      'product_id' => $product->id,
      'url' => $url,
      'error' => $e->getMessage(),
    ]);
  }
}
