<?php

namespace App\Models;

use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperProductSet
 */
class ProductSet extends Model
{
  use ModelTools, SoftDeletes;


  public function products(): ?BelongsToMany
  {
    return $this->belongsToMany(Product::class);
  }

}
