<?php

namespace App\Models;

use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;


class SupplementValue extends Model
{
  use ModelTools, SoftDeletes;


  protected $fillable = [
    'title',
    'price',
    'supplement_id',
    'product_id',
    'max_quantity',
    'track_stock',
    'position',
  ];

  protected $casts = [
    'price'        => 'integer',
    'product_id'   => 'integer',
    'max_quantity' => 'integer',
    'track_stock'  => 'boolean',
    'position'     => 'integer',
  ];

  public function product(): BelongsTo
  {
    return $this->belongsTo(Product::class);
  }

}
