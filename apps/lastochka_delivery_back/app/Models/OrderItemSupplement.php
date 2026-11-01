<?php

namespace App\Models;

use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;


class OrderItemSupplement extends Model
{
  use ModelTools, SoftDeletes;


  protected $fillable = [
    'cart_item_id',
    'supplement_id',
    'amount',
  ];


  public function supplement(): BelongsTo
  {
    return $this->belongsTo(Supplement::class);
  }

  public function values(): BelongsToMany
  {
    return $this->belongsToMany(SupplementValue::class,'join_order_supplements_values');
  }


}
