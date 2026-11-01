<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperCategory
 */
class Category extends Model
{
  use SoftDeletes;

  public function shopCategories(): HasMany
  {
    return $this->hasMany(ShopCategory::class);
  }
  public function products(): ?BelongsToMany
  {
    return $this->belongsToMany(Product::class);
  }

}
