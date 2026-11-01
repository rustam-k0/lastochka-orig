<?php

namespace App\Models;

use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperReplacementProduct
 */
class ReplacementProduct extends Model
{
  use ModelTools, SoftDeletes;

  protected $fillable = [
    'product_id',
    'assembly_item_id',
    'selected'
  ];

  public function product(): ?BelongsTo
  {
    return $this->belongsTo(Product::class);
  }

  public function scopeSelected($query): void
  {
    $query->whereSelected(true);
  }


}
