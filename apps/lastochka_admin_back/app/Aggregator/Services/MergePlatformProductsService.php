<?php

namespace App\Aggregator\Services;

use App\Models\PlatformProduct;
use App\Models\ProductOffer;
use Illuminate\Support\Facades\DB;

/**
 * Объединение дублей платформенных карточек (aggregator-режим, FOODLINK-10874).
 *
 * ОДНА операция для трёх входов: «Объединить» из карантина (double_match),
 * разовая чистка дублей из карточки и пакетное объединение в bootstrap (§8.3.1, §11.1).
 *
 * Офферы, остатки и штрихкоды переносятся на target; источник уходит в архив
 * (soft-delete deleted_at, НЕ hard-delete) — история заказов не ломается.
 */
class MergePlatformProductsService
{
  /**
   * Превью объединения без сохранения: сколько офферов перенесётся, суммарные
   * остатки, конфликты «поставщик уже есть на target».
   *
   * @param array<int, int> $sourceIds
   * @return array{offersCount: int, stocksSum: float, conflicts: array<int, array{offerId: int, shopId: int, message: string}>}
   */
  public function preview(int $targetId, array $sourceIds): array
  {
    $target = PlatformProduct::findOrFail($targetId);
    $targetShopIds = $target->offers()->pluck('shop_id')->all();

    $offersCount = 0;
    $stocksSum = 0.0;
    $conflicts = [];

    foreach ($this->sourcesExcludingTarget($targetId, $sourceIds) as $source) {
      foreach ($source->offers as $offer) {
        $offersCount++;
        $stocksSum += (float) $offer->qty;

        if (in_array($offer->shop_id, $targetShopIds, true)) {
          $conflicts[] = [
            'offerId' => (int) $offer->id,
            'shopId' => (int) $offer->shop_id,
            'message' => "Магазин #{$offer->shop_id} уже представлен на целевой карточке — оффер дубля будет отброшен (берём оффер целевой).",
          ];
        }
      }
    }

    return [
      'offersCount' => $offersCount,
      'stocksSum' => $stocksSum,
      'conflicts' => $conflicts,
    ];
  }

  /**
   * Выполнить объединение. Возвращает обновлённую target-карточку.
   *
   * @param array<int, int> $sourceIds
   */
  public function merge(int $targetId, array $sourceIds): PlatformProduct
  {
    $target = PlatformProduct::findOrFail($targetId);

    return DB::transaction(function () use ($target, $targetId, $sourceIds) {
      foreach ($this->sourcesExcludingTarget($targetId, $sourceIds) as $source) {
        $this->mergeOne($source, $target);
        $source->delete(); // soft-delete (архив)
      }

      $this->ensureSinglePrimaryBarcode($target);

      return $target->fresh(['barcodes', 'offers']);
    });
  }

  private function mergeOne(PlatformProduct $source, PlatformProduct $target): void
  {
    $targetShopIds = $target->offers()->pluck('shop_id')->all();

    foreach ($source->offers()->get() as $offer) {
      if (in_array($offer->shop_id, $targetShopIds, true)) {
        // Конфликт: тот же поставщик уже на target → оффер дубля отбрасываем.
        $offer->delete();
        continue;
      }
      $offer->update(['platform_product_id' => $target->id]);
      $targetShopIds[] = $offer->shop_id;
    }

    // Штрихкоды: UNIQUE(ean) глобально гарантирует отсутствие коллизий между
    // разными карточками — просто переносим. После переноса primary пересчитаем.
    $source->barcodes()->update([
      'platform_product_id' => $target->id,
      'is_primary' => false,
    ]);
  }

  /**
   * Гарантировать ровно один is_primary=1 на target (после переноса штрихкодов).
   */
  private function ensureSinglePrimaryBarcode(PlatformProduct $target): void
  {
    $hasPrimary = $target->barcodes()->where('is_primary', true)->exists();
    if ($hasPrimary) {
      // На случай нескольких primary (race) — оставить один.
      $primaryIds = $target->barcodes()->where('is_primary', true)->orderBy('id')->pluck('id');
      if ($primaryIds->count() > 1) {
        $target->barcodes()
          ->where('is_primary', true)
          ->whereKeyNot($primaryIds->first())
          ->update(['is_primary' => false]);
      }
      return;
    }

    $first = $target->barcodes()->orderBy('id')->first();
    if ($first) {
      $first->update(['is_primary' => true]);
    }
  }

  /**
   * @param array<int, int> $sourceIds
   * @return \Illuminate\Support\Collection<int, PlatformProduct>
   */
  private function sourcesExcludingTarget(int $targetId, array $sourceIds): \Illuminate\Support\Collection
  {
    $ids = collect($sourceIds)
      ->map(fn ($id) => (int) $id)
      ->reject(fn (int $id) => $id === $targetId)
      ->unique()
      ->values();

    return PlatformProduct::with('offers', 'barcodes')->whereIn('id', $ids)->get();
  }
}
