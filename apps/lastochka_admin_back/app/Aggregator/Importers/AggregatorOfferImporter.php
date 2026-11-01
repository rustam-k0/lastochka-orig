<?php

namespace App\Aggregator\Importers;

use App\Aggregator\Events\ProductImportedFromEvent;
use App\Models\AggregatorQuarantine;
use App\Models\PlatformProduct;
use App\Models\PlatformProductBarcode;
use App\Models\Product;
use App\Models\ProductHistory;
use App\Models\ProductOffer;
use App\Models\Shop;
use App\Models\Status;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Дедуп товаров по штрихкоду → платформенная карточка + оффер магазина
 * (aggregator-режим, FOODLINK-10874, docs/aggregator-mode.md §6.3).
 *
 * Срабатывает ПОСЛЕ стандартного ProductEventTransformer (через ProductImportedFromEvent),
 * не вместо него. Регистрируется в AggregatorServiceProvider только при aggregator_enabled.
 *
 * Две оси идентичности:
 *  - GUID 1С (вторичный ключ): обновления товара ТОГО ЖЕ поставщика находятся по
 *    (local_product_id, shop_id) ДО EAN-матчинга. Смена штрихкода у поставщика не плодит
 *    новую карточку.
 *  - EAN (первичная привязка нового товара + межпоставщиковое схлопывание): у разных
 *    поставщиков GUID разные, роднит только штрихкод.
 */
class AggregatorOfferImporter
{
  /**
   * Сменились ли штрихкоды карточки в этом проходе (для решения, публиковать ли
   * platform_product.updated через touch()).
   */
  private bool $barcodesChanged = false;

  public function handle(ProductImportedFromEvent $event): void
  {
    $product = $event->product;

    // Defense-in-depth: canonical платформенная карточка (sku='platform-*'), прилетевшая
    // обратно из Bagisto, НЕ должна порождать оффер — иначе петля (FOODLINK-10874): фантомный
    // товар → оффер qty=0. Источник офферов — товары поставщиков (1С/Bagisto), не canonical.
    // Проверяем sku из payload (у курьерской модели products колонки sku нет — sku приходит
    // в событии). Основной разрыв петли — PublishCondition на стороне Bagisto; это второй слой.
    if (str_starts_with((string) ($event->payload['sku'] ?? ''), 'platform-')) {
      return;
    }

    $shop = Shop::find($product->shop_id);

    if ($shop === null) {
      Log::channel('single')->warning('[aggregator] offer import skipped: shop not found', [
        'product_id' => $product->id,
        'shop_id' => $product->shop_id,
      ]);
      return;
    }

    $eans = $this->normalizeEans($event->payload['barcodes'] ?? []);

    DB::transaction(function () use ($product, $shop, $eans, $event) {
      $this->barcodesChanged = false;

      // 1. Вторичный ключ (GUID-стабильный): тот же товар того же поставщика уже привязан?
      $existingOffer = ProductOffer::where('local_product_id', $product->id)
        ->where('shop_id', $shop->id)
        ->lockForUpdate()
        ->first();

      if ($existingOffer) {
        $platform = PlatformProduct::lockForUpdate()->find($existingOffer->platform_product_id);
        if ($platform === null) {
          // Карточка исчезла/архивирована (soft-delete; FK ON DELETE CASCADE при этом
          // НЕ срабатывает). Осиротевший оффер удаляем, иначе updateOrCreate с новой
          // карточкой создаст дубль (нарушит идемпотентность). Пересоздаём по штрихкоду.
          $existingOffer->delete();
          $platform = $this->resolvePlatformByEan($product, $shop, $eans, $event->payload);
          if ($platform === null) {
            return; // ушёл в карантин
          }
        }
      } else {
        // 2. Первичная привязка нового товара — по штрихкоду.
        $platform = $this->resolvePlatformByEan($product, $shop, $eans, $event->payload);
        if ($platform === null) {
          return; // ушёл в карантин (no_ean / double_match)
        }
      }

      $this->syncBarcodes($platform, $eans, $shop->id, $product, $event->payload);

      $offer = ProductOffer::updateOrCreate(
        ['platform_product_id' => $platform->id, 'shop_id' => $shop->id],
        [
          'local_product_id' => $product->id,
          // Цена товара поставщика = его defaultPrice (товар принадлежит одному магазину,
          // поэтому отдельный фильтр по shop_id не нужен; см. docs §6.3). prices.shop_id —
          // store_back-колонка, не во всех средах присутствует, на неё не завязываемся.
          'price' => (int) ($product->defaultPrice?->value ?? 0),
          'qty' => (float) ($product->remains()->where('shop_id', $shop->id)->sum('quantity')),
          'min_qty' => (float) ($event->payload['min_qty'] ?? 1),
          'is_active' => $product->status_id === Status::CODE_ACTIVE,
          'last_sync_at' => now(),
        ]
      );

      // Если штрихкоды дописаны — обновляем карточку, чтобы platform_product.updated
      // переопубликовал актуальный список штрихкодов в Bagisto.
      if ($this->barcodesChanged && !$platform->wasRecentlyCreated) {
        $platform->touch();
      }
    });
  }

  /**
   * Резолв платформенной карточки по штрихкоду (для первичной привязки нового товара).
   * Возвращает null если товар ушёл в карантин (no_ean / double_match).
   *
   * @param array<int, string> $eans
   * @param array<string, mixed> $payload
   */
  private function resolvePlatformByEan(Product $product, Shop $shop, array $eans, array $payload): ?PlatformProduct
  {
    if (empty($eans)) {
      $this->quarantine($product, $shop, AggregatorQuarantine::REASON_NO_EAN, $payload);
      return null;
    }

    $matched = PlatformProductBarcode::query()
      ->whereIn('ean', $eans)
      ->lockForUpdate()
      ->get();

    $distinctPlatformIds = $matched->pluck('platform_product_id')->unique()->values();

    if ($distinctPlatformIds->count() > 1) {
      // Двойное совпадение: штрихкоды матчат разные карточки → единый карантин,
      // оффер не создаём, админ разбирает действием «Объединить».
      $this->quarantine(
        $product,
        $shop,
        AggregatorQuarantine::REASON_DOUBLE_MATCH,
        $payload,
        $distinctPlatformIds->all()
      );
      return null;
    }

    if ($distinctPlatformIds->count() === 1) {
      return PlatformProduct::lockForUpdate()->find($distinctPlatformIds->first());
    }

    return $this->createPlatformProduct($payload);
  }

  /**
   * Создать платформенную карточку. В MVP 10874 canonical_title берётся из исходного
   * названия товара (полноценный CanonicalResolver — [Задача 02]).
   *
   * @param array<string, mixed> $payload
   */
  private function createPlatformProduct(array $payload): PlatformProduct
  {
    $title = trim((string) ($payload['title'] ?? ''));

    return PlatformProduct::create([
      'canonical_title' => $title !== '' ? $title : 'Товар без названия',
      'is_active' => true,
      'is_curated' => false,
    ]);
  }

  /**
   * Дописать новые штрихкоды к карточке. При коллизии по UNIQUE(ean) — штрихкод уже
   * принадлежит ДРУГОЙ карточке → двойное совпадение, обнаруженное на вставке.
   *
   * @param array<int, string> $eans
   * @param array<string, mixed> $payload
   */
  private function syncBarcodes(PlatformProduct $platform, array $eans, int $sourceShopId, Product $product, array $payload): void
  {
    $existing = $platform->barcodes()->pluck('ean')->all();
    $newOnes = array_values(array_diff($eans, $existing));

    if (empty($newOnes)) {
      return;
    }

    $isFirstBarcodeOfCard = empty($existing);

    foreach ($newOnes as $index => $ean) {
      try {
        PlatformProductBarcode::create([
          'platform_product_id' => $platform->id,
          'ean' => $ean,
          'is_primary' => $isFirstBarcodeOfCard && $index === 0,
          'source_shop_id' => $sourceShopId,
        ]);
        $this->barcodesChanged = true;
      } catch (QueryException $e) {
        if ($this->isUniqueViolation($e)) {
          // Гонка/межпоставщиковое: этот EAN успел занять другую карточку.
          $conflict = PlatformProductBarcode::where('ean', $ean)->first();
          $conflictIds = array_values(array_unique(array_filter([
            $platform->id,
            $conflict?->platform_product_id,
          ])));
          $this->quarantine(
            $product,
            Shop::find($sourceShopId),
            AggregatorQuarantine::REASON_DOUBLE_MATCH,
            array_merge($payload, ['conflicting_ean' => $ean]),
            $conflictIds
          );
          continue;
        }
        throw $e;
      }
    }
  }

  /**
   * Нормализация штрихкодов: только цифры, любая непустая цифровая строка принимается
   * (жёсткую длину 8/12/13/14 НЕ навязываем — см. §6.3, открытый вопрос §16.12).
   *
   * @param mixed $eans
   * @return array<int, string>
   */
  private function normalizeEans($eans): array
  {
    if (!is_array($eans)) {
      $eans = $eans === null ? [] : [$eans];
    }

    return collect($eans)
      ->map(fn ($ean) => preg_replace('/\D/', '', (string) $ean))
      ->filter(fn ($ean) => $ean !== '')
      ->unique()
      ->values()
      ->all();
  }

  /**
   * Персистентная запись в карантин + скрытие товара с витрины.
   * Идемпотентно: повторный импорт того же товара не плодит дубли записей карантина.
   *
   * @param array<string, mixed> $payload
   * @param array<int, int> $matchedPlatformIds
   */
  private function quarantine(
    ?Product $product,
    ?Shop $shop,
    string $reason,
    array $payload,
    array $matchedPlatformIds = []
  ): void {
    $shopId = $shop?->id ?? $product?->shop_id;
    if ($shopId === null) {
      Log::channel('single')->warning('[aggregator] quarantine skipped: no shop', [
        'reason' => $reason,
        'product_id' => $product?->id,
      ]);
      return;
    }

    $title = $payload['title'] ?? $product?->title;

    $existing = AggregatorQuarantine::query()
      ->whereNull('resolved_at')
      ->where('shop_id', $shopId)
      ->where('local_product_id', $product?->id)
      ->where('reason', $reason)
      ->first();

    if ($existing) {
      $existing->update([
        'incoming_title' => $title,
        'matched_platform_product_ids' => $matchedPlatformIds ?: null,
        'payload_snapshot' => $payload,
      ]);
    } else {
      AggregatorQuarantine::create([
        'shop_id' => $shopId,
        'local_product_id' => $product?->id,
        'incoming_title' => $title,
        'reason' => $reason,
        'matched_platform_product_ids' => $matchedPlatformIds ?: null,
        'payload_snapshot' => $payload,
      ]);
    }

    // Скрыть товар с витрины (не публикуется, пока админ не разберёт карантин).
    $hiddenStatusId = config('aggregator.quarantine_hidden_status_id');
    if ($product !== null && $hiddenStatusId !== null && $product->status_id !== (int) $hiddenStatusId) {
      $oldStatusId = $product->status_id;
      $product->withoutEvents(function () use ($product, $hiddenStatusId) {
        $product->update(['status_id' => (int) $hiddenStatusId]);
      });

      // Аудит-трейл (LASTOCHKI-JAMI-11983 §5): карантин — единственный автоматический
      // процесс, которому осознанно разрешено менять статус активности (защита от
      // конфликта штрихкодов), но это изменение всё равно должно быть видно в истории
      // с пометкой источника — иначе "почему товар пропал с витрины" неотличимо от
      // ручной деактивации при разборе инцидента.
      ProductHistory::log(
        $product->id,
        ProductHistory::ACTION_STATUS_CHANGED,
        ProductHistory::ACTOR_SYSTEM,
        null,
        'aggregator_quarantine',
        (string) $oldStatusId,
        (string) $hiddenStatusId,
        "Скрыт карантином агрегатора: {$reason}"
      );
    }
  }

  private function isUniqueViolation(QueryException $e): bool
  {
    // SQLSTATE 23000 = integrity constraint violation (MySQL: Duplicate entry для UNIQUE).
    return (string) $e->getCode() === '23000';
  }
}
