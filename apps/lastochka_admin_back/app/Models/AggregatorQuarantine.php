<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Карантин товаров, не попавших на витрину (aggregator-режим, FOODLINK-10874).
 *
 * reason: no_ean (нет штрихкода) | double_match (штрихкоды совпали с разными
 * карточками). resolved_at гасит запись и убирает из счётчика. См. §5.1, §8.4.
 */
class AggregatorQuarantine extends Model
{
  public const REASON_NO_EAN = 'no_ean';
  public const REASON_DOUBLE_MATCH = 'double_match';

  protected $table = 'aggregator_quarantine';

  protected $fillable = [
    'shop_id',
    'local_product_id',
    'incoming_title',
    'reason',
    'matched_platform_product_ids',
    'payload_snapshot',
    'resolved_at',
    'resolved_by',
  ];

  protected $casts = [
    'matched_platform_product_ids' => 'array',
    'payload_snapshot' => 'array',
    'resolved_at' => 'datetime',
  ];

  public function shop(): BelongsTo
  {
    return $this->belongsTo(Shop::class);
  }

  public function localProduct(): BelongsTo
  {
    return $this->belongsTo(Product::class, 'local_product_id');
  }

  public function getShopTitleAttribute(): ?string
  {
    return $this->shop?->title;
  }

  public function scopeUnresolved(Builder $query): Builder
  {
    return $query->whereNull('resolved_at');
  }

  public function scopeReason(Builder $query, ?string $reason): Builder
  {
    if ($reason === null || $reason === '') {
      return $query;
    }

    return $query->where('reason', $reason);
  }

  public function scopeResolvedFilter(Builder $query, ?bool $resolved): Builder
  {
    if ($resolved === null) {
      return $query;
    }

    return $resolved ? $query->whereNotNull('resolved_at') : $query->whereNull('resolved_at');
  }

  /**
   * Фильтр по магазину; null — без фильтра (FOODLINK-11191).
   * @scope(name: "filterByShop").
   */
  public function scopeFilterByShop(Builder $query, ?int $shopId): Builder
  {
    if ($shopId === null) {
      return $query;
    }

    return $query->where('shop_id', $shopId);
  }

  /**
   * matched_platform_product_ids как JSON-строка для GraphQL (тип String).
   * @method(name: "matchedIdsJson").
   */
  public function matchedIdsJson(): ?string
  {
    return $this->matched_platform_product_ids
      ? json_encode($this->matched_platform_product_ids)
      : null;
  }
}
