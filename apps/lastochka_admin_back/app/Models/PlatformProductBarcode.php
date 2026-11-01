<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Штрихкод платформенного товара (aggregator-режим, FOODLINK-10874).
 * UNIQUE по ean — один EAN принадлежит ровно одной карточке (§5.1).
 */
class PlatformProductBarcode extends Model
{
  protected $table = 'platform_product_barcodes';

  protected $fillable = [
    'platform_product_id',
    'ean',
    'is_primary',
    'source_shop_id',
  ];

  protected $casts = [
    'is_primary' => 'boolean',
  ];

  public function platformProduct(): BelongsTo
  {
    return $this->belongsTo(PlatformProduct::class);
  }
}
