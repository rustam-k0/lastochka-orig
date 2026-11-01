<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Оффер магазина-поставщика на платформенный товар (aggregator-режим, FOODLINK-10874).
 *
 * (platform_product_id, shop_id) UNIQUE. Денормализованные price/qty для витрины.
 * Вторичный ключ (local_product_id, shop_id) = стабильный id товара поставщика
 * по GUID 1С (см. docs/aggregator-mode.md §5.1, §6.3).
 *
 * Публикуется в RabbitMQ через ModelEventObserver (entity 'product_offers',
 * routing aggregator.offer.*), регистрируется в AggregatorServiceProvider.
 */
class ProductOffer extends Model
{
  protected $table = 'product_offers';

  protected $fillable = [
    'platform_product_id',
    'shop_id',
    'local_product_id',
    'price',
    'qty',
    'min_qty',
    'is_active',
    'sort',
    'last_sync_at',
  ];

  protected $casts = [
    'price' => 'integer',
    'qty' => 'decimal:3',
    'min_qty' => 'decimal:3',
    'is_active' => 'boolean',
    'sort' => 'integer',
    'last_sync_at' => 'datetime',
  ];

  public function platformProduct(): BelongsTo
  {
    return $this->belongsTo(PlatformProduct::class);
  }

  public function shop(): BelongsTo
  {
    return $this->belongsTo(Shop::class);
  }

  /**
   * Запись в products того же поставщика (стабильна по GUID 1С).
   * Источник «исходного названия» и «id товара у поставщика» для админки.
   */
  public function localProduct(): BelongsTo
  {
    return $this->belongsTo(Product::class, 'local_product_id');
  }

  /**
   * Исходное название товара у поставщика (для таблицы офферов в админке, §8.3).
   */
  public function getLocalProductTitleAttribute(): ?string
  {
    return $this->localProduct?->title;
  }

  public function getShopTitleAttribute(): ?string
  {
    return $this->shop?->title;
  }
}
