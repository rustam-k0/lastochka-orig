<?php

namespace App\Aggregator\Transformers;

use App\Models\ProductOffer;
use Comrades\RabbitMQEvents\Concerns\HasEventHandlerHooks;
use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Публикация офферов в RabbitMQ (aggregator-режим, FOODLINK-10874).
 *
 * Routing: aggregator.offer.created/updated (upsert) и aggregator.offer.deleted.
 * Bagisto-консьюмер (OfferEventTransformer на стороне Bagisto) обновляет
 * marketplace_products + product_inventories. См. docs/aggregator-mode.md §6.7, §7.1.
 *
 * Только publisher: admin_back офферы не потребляет, он их источник истины (§3.6).
 *
 * @method static array toEvent(\App\Models\ProductOffer $offer)
 */
class OfferEventTransformer implements EventTransformer
{
  use ValidatesEventData;
  use HasEventHandlerHooks;

  protected array $requiredFields = [
    'event_id',
    'id',
    'source',
  ];

  /**
   * @param ProductOffer $offer
   */
  public function toEvent(Model $offer, ?object $relatedModel = null, ?string $eventId = null): array
  {
    $platform = $offer->platformProduct;
    $shop = $offer->shop;

    return [
      'event_id' => $eventId ?? Str::uuid()->toString(),
      'event_timestamp' => ($offer->updated_at ?? now())->toIso8601String(),
      'id' => (string) $offer->id,
      'source' => config('rabbitmq-events.source'),
      'external_shop_code' => $shop?->code ?? ('shop_' . $offer->shop_id),
      'external_platform_id' => (int) $offer->platform_product_id,
      'primary_ean' => $this->resolvePrimaryEan($platform),
      'price' => (int) $offer->price,
      'qty' => (float) $offer->qty,
      'min_qty' => (float) $offer->min_qty,
      'is_active' => (bool) $offer->is_active,
      'last_sync_at' => $offer->last_sync_at?->toIso8601String(),
    ];
  }

  /**
   * admin_back не потребляет offer-события (он их источник). Стаб для контракта.
   */
  public function fromEvent(array $eventData): array
  {
    return [];
  }

  private function resolvePrimaryEan(?Model $platform): ?string
  {
    if ($platform === null) {
      return null;
    }

    $barcodes = $platform->relationLoaded('barcodes') ? $platform->barcodes : $platform->barcodes()->get();

    return $barcodes->firstWhere('is_primary', true)?->ean
      ?? $barcodes->first()?->ean;
  }
}
