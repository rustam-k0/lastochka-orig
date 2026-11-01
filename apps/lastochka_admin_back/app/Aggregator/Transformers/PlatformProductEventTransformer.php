<?php

namespace App\Aggregator\Transformers;

use App\Models\PlatformProduct;
use Comrades\RabbitMQEvents\Concerns\HasEventHandlerHooks;
use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Публикация платформенных карточек в RabbitMQ (aggregator-режим, FOODLINK-10874).
 *
 * Routing: aggregator.platform_product.created/updated/deleted. Bagisto-консьюмер
 * создаёт/обновляет canonical products запись + пишет barcodes в additional.
 * См. docs/aggregator-mode.md §6.7, §7.2. Только publisher.
 *
 * @method static array toEvent(\App\Models\PlatformProduct $platform)
 */
class PlatformProductEventTransformer implements EventTransformer
{
  use ValidatesEventData;
  use HasEventHandlerHooks;

  protected array $requiredFields = [
    'event_id',
    'id',
    'source',
  ];

  /**
   * @param PlatformProduct $platform
   */
  public function toEvent(Model $platform, ?object $relatedModel = null, ?string $eventId = null): array
  {
    $barcodes = $platform->relationLoaded('barcodes') ? $platform->barcodes : $platform->barcodes()->get();
    $primaryEan = $barcodes->firstWhere('is_primary', true)?->ean ?? $barcodes->first()?->ean;

    return [
      'event_id' => $eventId ?? Str::uuid()->toString(),
      'event_timestamp' => ($platform->updated_at ?? now())->toIso8601String(),
      'id' => (string) $platform->id,
      'source' => config('rabbitmq-events.source'),
      'external_platform_id' => (int) $platform->id,

      // Product-совместимые поля — Bagisto-консьюмер переиспользует проверенный
      // ProductEventTransformer (EAV). sku='platform-{id}' — canonical карточка без хозяина.
      'sku' => 'platform-' . $platform->id,
      'shop_code' => 'platform', // sentinel: канон без seller → inventory vendor_id=0
      'title' => $platform->canonical_title,
      'description' => $platform->canonical_description ?? '',
      'price' => 0, // цены приходят через офферы (marketplace_products)
      'unit_type' => 'piece',
      'quantity' => 0,
      'active' => (bool) $platform->is_active,
      // Bagisto setBarcodes ожидает плоский список ean.
      'barcodes' => $barcodes->pluck('ean')->values()->all(),
      'primary_barcode' => $primaryEan,

      // Канонические поля (для будущей курации [Задача 02] и аудита).
      'canonical_title' => $platform->canonical_title,
      'canonical_image' => $platform->canonical_image,
      'canonical_description' => $platform->canonical_description,
      'canonical_attributes' => $platform->canonical_attributes,
      'barcodes_detailed' => $barcodes->map(fn ($b) => [
        'ean' => $b->ean,
        'is_primary' => (bool) $b->is_primary,
      ])->values()->all(),
      'category_external_id' => $platform->category_id,
      'is_active' => (bool) $platform->is_active,
      'deleted_at' => $platform->deleted_at?->toIso8601String(),
    ];
  }

  public function fromEvent(array $eventData): array
  {
    return [];
  }
}
