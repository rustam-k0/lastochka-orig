<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Каноническая карточка платформенного товара (aggregator-режим, FOODLINK-10874).
 *
 * Уникальна по набору штрихкодов (platform_product_barcodes). Имеет N офферов
 * (product_offers) от разных магазинов-поставщиков. См. docs/aggregator-mode.md §5.1.
 */
class PlatformProduct extends Model
{
  use SoftDeletes;

  protected $fillable = [
    'canonical_title',
    'canonical_description',
    'canonical_image',
    'canonical_attributes',
    'category_id',
    'measurement_unit_id',
    'is_active',
    'is_curated',
  ];

  protected $casts = [
    'canonical_attributes' => 'array',
    'is_active' => 'boolean',
    'is_curated' => 'boolean',
  ];

  public function barcodes(): HasMany
  {
    return $this->hasMany(PlatformProductBarcode::class);
  }

  public function offers(): HasMany
  {
    return $this->hasMany(ProductOffer::class);
  }

  /**
   * Назначить один штрихкод основным (is_primary=1), сбросив остальные.
   * Инвариант «ровно один primary на карточку» поддерживается на уровне приложения
   * (см. docs/aggregator-mode.md §5.1).
   */
  public function setPrimaryBarcode(int $barcodeId): void
  {
    DB::transaction(function () use ($barcodeId) {
      $this->barcodes()->where('is_primary', true)->update(['is_primary' => false]);
      $this->barcodes()->whereKey($barcodeId)->update(['is_primary' => true]);
    });
  }

  public function primaryBarcode(): ?PlatformProductBarcode
  {
    return $this->barcodes()->where('is_primary', true)->first()
      ?? $this->barcodes()->first();
  }

  /**
   * Поиск по canonical_title или по любому штрихкоду (не только primary).
   * Используется GraphQL-директивой @scope(name: "search").
   */
  public function scopeSearch(Builder $query, ?string $value): Builder
  {
    if ($value === null || $value === '') {
      return $query;
    }

    return $query->where(function (Builder $q) use ($value) {
      $q->where('canonical_title', 'LIKE', "%{$value}%")
        ->orWhereHas('barcodes', function (Builder $b) use ($value) {
          $b->where('ean', 'LIKE', "%{$value}%");
        });
    });
  }

  /**
   * Только карточки, у которых есть оффер в указанном магазине.
   * @scope(name: "filterByShop").
   */
  public function scopeFilterByShop(Builder $query, ?int $shopId): Builder
  {
    if ($shopId === null) {
      return $query;
    }

    return $query->whereHas('offers', function (Builder $o) use ($shopId) {
      $o->where('shop_id', $shopId);
    });
  }

  /**
   * Только карточки, у которых есть активный оффер в указанном магазине.
   * @scope(name: "onlyHavingOffers").
   */
  public function scopeOnlyHavingOffers(Builder $query, ?int $shopId): Builder
  {
    if ($shopId === null) {
      return $query;
    }

    return $query->whereHas('offers', function (Builder $o) use ($shopId) {
      $o->where('shop_id', $shopId)->where('is_active', true);
    });
  }

  public function getOffersCountAttribute(): int
  {
    return $this->offers()->count();
  }

  /**
   * canonical_attributes как JSON-строка для GraphQL (тип String).
   * Используется директивой @method(name: "canonicalAttributesJson").
   */
  public function canonicalAttributesJson(): ?string
  {
    return $this->canonical_attributes
      ? json_encode($this->canonical_attributes, JSON_UNESCAPED_UNICODE)
      : null;
  }
}
